<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Comunicazione;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Notifications\Assemblee\PromemoriaDellAssembleaNotification;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Il promemoria della sospensione (decisione 595 (1), scena 11 della bozza approvata): quando la presidente sospende e l'assemblea prosegue in una data
 * dell'avviso, **un'email a ognuno dei convocati che ha l'indirizzo** (il giorno, l'ora, il luogo e i punti che restano; le deleghe già date valgono) e
 * **un avviso in bacheca per tutti**, con il giorno, l'ora, il luogo e i numeri dei punti: **nessun nome**. Non è una convocazione: la data era già nell'avviso,
 * e il messaggio lo dice. Lo manda il gesto «Sospendi» con l'interruttore acceso di serie.
 *
 * - **Chi è convocato solo per alcuni punti** (il conduttore, decisione 566: `assemblee_convocati.punti`) riceve solo i suoi punti che restano, e nessuna
 *   email se non ne resta nessuno: i punti degli altri (con il loro testo e le loro cifre) non gli arrivano.
 * - **Senza un server di posta** (la posta sul trasporto `log`) nessuna email e nessuna riga «inviata»: resta l'avviso in bacheca, e il messaggio lo dice.
 */
final class PromemoriaDellaSospensione
{
    public const TIPO = 'promemoria_assemblea';

    /**
     * Manda il promemoria; rende quante email e se l'avviso in bacheca è nato. Un errore della posta non ferma la sospensione: la riga resta «non partita».
     *
     * @return array{email: int, bacheca: bool, senza_posta: bool}
     */
    public function manda(Assemblea $assemblea, SedutaDellAssemblea $sospesa, SedutaDellAssemblea $prosegue, User $utente, bool $pubblica = true): array
    {
        $assemblea->loadMissing('condominio');
        $testi = $this->testi($assemblea, $sospesa, $prosegue);
        $email = 0;
        $senzaPosta = \App\Services\Gestionale\Ricevute\EmissioneRicevute::senzaServerPosta();
        $convocati = $senzaPosta ? collect() : ConvocatoDellAssemblea::query()->where('assemblea_id', $assemblea->id)->get();
        $indirizzi = Anagrafica::query()->whereIn('id', $convocati->pluck('anagrafica_id'))->pluck('email', 'id');
        foreach ($convocati as $c) {
            $indirizzo = trim((string) ($indirizzi[$c->anagrafica_id] ?? ''));
            if ($indirizzo === '' || filter_var($indirizzo, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            // Convocato solo per alcuni punti: i suoi che restano, o niente.
            $suoi = $c->punti === null ? null : array_map('intval', (array) $c->punti);
            $righe = $suoi === null ? $testi['righe'] : $this->testi($assemblea, $sospesa, $prosegue, $suoi)['righe'];
            if ($suoi !== null && $this->restano($assemblea, $suoi)->isEmpty()) {
                continue;
            }
            $gettone = (string) Str::uuid();
            $riga = RegistroInvio::create(['condominio_id' => $assemblea->condominio_id, 'anagrafica_id' => (int) $c->anagrafica_id, 'tipo' => self::TIPO,
                'canale' => RegistroInvio::CANALE_EMAIL, 'documentabile_type' => $assemblea->getMorphClass(), 'documentabile_id' => $assemblea->id,
                'destinatario' => $indirizzo, 'stato' => RegistroInvio::STATO_IN_CODA, 'emesso_da' => $utente->id, 'emesso_il' => now(),
                'dati' => ['oggetto' => $testi['oggetto'], 'righe' => $righe, 'persona' => ['nome' => (string) $c->nome], 'gettone' => $gettone,
                    'seduta_id' => (int) $prosegue->id]]);
            try {
                $notifica = new PromemoriaDellAssembleaNotification([(int) $riga->id]);
                $notifica->id = $gettone;
                Notification::route('mail', $indirizzo)->notify($notifica);
            } catch (\Throwable $e) {
                Log::warning('Promemoria dell\'assemblea non partito: ' . $e->getMessage(), ['invio' => $riga->id]);
                $riga->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => mb_substr($e->getMessage(), 0, 1000)]);
            }
            $email++;
        }
        // L'avviso in bacheca: pubblicato subito, o in bozza da pubblicare (la scelta della finestra «Sospendi»).
        $bacheca = \Illuminate\Support\Facades\DB::transaction(function () use ($assemblea, $testi, $utente, $pubblica) {
            $comunicazione = Comunicazione::create(['subject' => $testi['bacheca']['oggetto'], 'description' => $testi['bacheca']['testo'], 'priority' => 'media',
                'is_featured' => false, 'is_private' => false, 'is_published' => $pubblica, 'is_approved' => true, 'can_comment' => false, 'created_by' => $utente->id]);
            RegistroModifiche::legame($comunicazione, 'condomini', 'Condomini', fn () => $comunicazione->condomini()->attach([(int) $assemblea->condominio_id]));

            return $comunicazione;
        });

        return ['email' => $email, 'bacheca' => $bacheca !== null, 'senza_posta' => $senzaPosta];
    }

    /** I punti senza esito, solo fra quelli dati (null: tutti). @param ?list<int> $numeri */
    private function restano(Assemblea $assemblea, ?array $numeri): \Illuminate\Support\Collection
    {
        $restano = \App\Services\Assemblee\Sala\StatiDellaSala::puntiSenzaEsito($assemblea);

        return $numeri === null ? $restano : $restano->filter(fn ($p) => in_array((int) $p->numero, $numeri, true))->values();
    }

    /**
     * I testi: l'oggetto e le righe dell'email (con i punti che restano, il loro testo; per chi è convocato solo per alcuni, solo i suoi), l'avviso in bacheca
     * (solo i numeri dei punti, nessun nome).
     *
     * @param ?list<int> $soloIPunti i punti per cui la persona è convocata (il conduttore), o null per tutti
     * @return array{oggetto: string, righe: list<string>, bacheca: array{oggetto: string, testo: string}}
     */
    public function testi(Assemblea $assemblea, SedutaDellAssemblea $sospesa, SedutaDellAssemblea $prosegue, ?array $soloIPunti = null): array
    {
        $condominio = (string) $assemblea->condominio?->nome;
        $luogo = trim((string) ($prosegue->luogo ?: $assemblea->luogo));
        $restano = $this->restano($assemblea, $soloIPunti);
        $quando = ParoleDelVerbale::giornoConLaSettimana($prosegue->fissata_il->format('Y-m-d')) . ' alle ore ' . $prosegue->fissata_il->format('H:i');
        $breve = \App\Services\Assemblee\PassiDellAssemblea::giornoDellaSeduta($prosegue->fissata_il);
        $tipo = mb_strtolower((string) $assemblea->tipo?->parola());

        return [
            'oggetto' => $condominio . ': l\'assemblea prosegue ' . $breve,
            'righe' => array_values(array_filter([
                'L\'assemblea ' . $tipo . ' del ' . $sospesa->fissata_il->format('d/m/Y') . ' è stata sospesa alle ' . ParoleDelVerbale::ora($sospesa->chiusa_il ?? now())
                    . ' e prosegue ' . $quando . ($luogo !== '' ? ', ' . $luogo : '') . ', come indicato nell\'avviso di convocazione.',
                $restano->isEmpty() ? null : 'Restano da trattare: ' . implode('; ', $restano->map(fn ($p) => $p->numero . '. ' . trim((string) $p->testo))->all()) . '.',
                'Le deleghe già date valgono anche ' . ParoleDelVerbale::giornoConLaSettimana($prosegue->fissata_il->format('Y-m-d')) . '. Questo è un promemoria: '
                    . 'la convocazione resta quella che hai ricevuto.',
            ])),
            'bacheca' => [
                'oggetto' => 'Assemblea ' . $tipo . ': prosegue ' . $breve,
                'testo' => 'L\'assemblea ' . $tipo . ' dei condòmini del ' . TestiLettere::condominioConNome($condominio) . ' prosegue ' . $quando
                    . ($luogo !== '' ? ', ' . $luogo : '') . '.' . ($restano->isEmpty() ? '' : ' Restano ' . ParoleDelVerbale::punti($restano->pluck('numero')->all()) . '.')
                    . "\n" . 'Questo avviso non vale come convocazione: la data era già nell\'avviso di convocazione.',
            ],
        ];
    }
}
