<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\EsitoDelGesto;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\DichiarazioneAVerbale;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\User;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * I gesti della sala (R9; la tenuta senza rete, decisioni 514 (5) e 519): ogni azione della sala arriva come **gesto numerato** dal dispositivo e passa da
 * qui, **in ordine**, ognuno **nella sua transazione**, con la sua risposta.
 *
 * - **Il numero** (N4): `assemblee_gesti.gesto` è unico; un gesto che arriva due volte risponde `doppio` e conta una volta (anche due richieste insieme:
 *   la seconda trova il numero già scritto e torna indietro).
 * - **Le due versioni** (N5): il gesto porta la versione da cui è partito; se nel frattempo è cambiata in un altro modo, il gesto è `in_conflitto`, niente si
 *   sovrascrive, e «Scegli» ({@see scegli()}) lo applica o lo lascia; una votazione con una scelta aperta non si conferma.
 * - **L'orologio** (N6): l'ora del gesto oltre due minuti nel futuro si rifiuta; l'arrivo si scrive sempre.
 * - **Il controllo**: ogni gesto si controlla come una richiesta (l'assemblea è quella della rotta, la persona, il punto e la votazione sono suoi, il gesto
 *   è possibile nello stato di quel momento), e i conti li rifà il server: quelli del dispositivo non si leggono.
 * - **Il registro**: ogni gesto applicato è una riga con l'ora del gesto e l'ora di arrivo, salvo il voto prima della conferma (la riga è della conferma).
 *
 * Le risposte: `{gesto, tipo, esito, messaggio, versione, conflitto: {questa, quella, quella_dispositivo?, quella_segnato_il?}|null, …}`; `esito` è
 * `applicato`, `doppio` (con i numeri della prima volta), `in_conflitto`, `rifiutato`.
 */
final class GestiDellaSala
{
    /** Il tetto di un lotto. */
    public const TETTO = 500;

    /** Quanto avanti può essere l'ora di un gesto rispetto al server. */
    public const SECONDI_AVANTI = 120;

    /** I tipi di gesto, con il servizio e il metodo che lo applicano. */
    public const TIPI = [
        'deserta' => [StatiDellaSala::class, 'deserta'],
        'apri' => [StatiDellaSala::class, 'apri'],
        'apertura_decidi' => [StatiDellaSala::class, 'decidiLApertura'],
        'sospendi' => [StatiDellaSala::class, 'sospendi'],
        'rinvia' => [StatiDellaSala::class, 'rinvia'],
        'chiudi' => [StatiDellaSala::class, 'chiudi'],
        'entra' => [PresenzeDellaSala::class, 'entra'],
        'esce' => [PresenzeDellaSala::class, 'esce'],
        'rappresenta' => [PresenzeDellaSala::class, 'rappresentaLeUnita'],
        'delega' => [DelegheDellAssemblea::class, 'gestoInSala'],
        'delega_revoca' => [DelegheDellAssemblea::class, 'revocaInSala'],
        'delega_decidi' => [DelegheDellAssemblea::class, 'decidi'],
        'si_vota' => [VotazioniDellaSala::class, 'siVota'],
        'tutti_favorevoli' => [VotazioniDellaSala::class, 'tuttiFavorevoli'],
        'voto' => [VotazioniDellaSala::class, 'voto'],
        'conflitto' => [VotazioniDellaSala::class, 'conflitto'],
        'conferma' => [VotazioniDellaSala::class, 'conferma'],
        'trattato' => [VotazioniDellaSala::class, 'trattato'],
        'dichiarazione' => [VotazioniDellaSala::class, 'dichiarazione'],
        'dichiarazione_cambia' => [VotazioniDellaSala::class, 'cambiaLaDichiarazione'],
        'dichiarazione_togli' => [VotazioniDellaSala::class, 'togliLaDichiarazione'],
        'nota' => [VotazioniDellaSala::class, 'nota'],
    ];

    /**
     * Il lotto di un dispositivo, già controllato nella forma: le risposte gesto per gesto, nell'ordine.
     *
     * @param list<array{gesto: string, tipo: string, dati?: ?array, base?: ?int, orologio?: ?string, segnato_il: string}> $gesti
     * @return list<array<string, mixed>>
     */
    public function ricevi(Assemblea $assemblea, string $dispositivo, array $gesti, User $utente): array
    {
        $risposte = [];
        foreach ($gesti as $gesto) {
            // La discussione di un punto scritta nella sala (decisione 595 (4)) è un salvataggio del verbale: la stessa coda, il servizio del verbale.
            $risposte[] = ($gesto['tipo'] ?? null) === \App\Services\Assemblee\Verbale\GestiDelVerbale::TIPO
                ? app(\App\Services\Assemblee\Verbale\GestiDelVerbale::class)->uno($assemblea, $dispositivo, $gesto, $utente)
                : $this->uno($assemblea, $dispositivo, $gesto, $utente);
        }
        // I compiti della sala e del verbale (decisioni 595 (1) e 604 (3)): la sala in corso, da riaprire, le due versioni, ciò che è da decidere.
        app(\App\Services\Assemblee\Verbale\CompitiDelVerbale::class)->sincronizza($assemblea);

        return $risposte;
    }

    /**
     * «Scegli» fra due versioni: `questa` applica il gesto in conflitto (senza più controllare la versione), `quella` lo lascia; in tutti e due i casi la
     * scelta è una riga. È anch'esso un gesto, e passa da {@see ricevi()}.
     */
    public function scegli(GestoInArrivo $g): array
    {
        $conflitto = GestoDellaSala::query()->where('assemblea_id', $g->assemblea->id)->where('gesto', (string) $g->dato('gesto'))
            ->where('esito', EsitoDelGesto::IN_CONFLITTO->value)->whereNull('scelto_il')->first()
            ?? throw ValidationException::withMessages(['gesto' => 'Non c\'è una scelta aperta per questo gesto.']);
        $scelta = (string) $g->dato('scelta');
        if (! in_array($scelta, ['questa', 'quella'], true)) {
            throw ValidationException::withMessages(['gesto' => 'Scegli «questa» (il gesto del dispositivo) o «quella» (com\'è adesso).']);
        }
        $questa = (string) ($conflitto->conflitto['questa'] ?? '');
        $quella = (string) ($conflitto->conflitto['quella'] ?? '');
        $frase = 'fra due versioni scelta «' . ($scelta === 'questa' ? $questa : $quella) . '» (l\'altra era «' . ($scelta === 'questa' ? $quella : $questa) . '»)';
        // Ciò che il gesto rimesso ha cambiato oltre sé stesso (un voto che non conta più) sta nella stessa riga: la riga è una per gesto.
        $conLeConseguenze = fn ($lotto, $esito) => $frase . (filled($esito['messaggio'] ?? null) && ! str_starts_with((string) $esito['messaggio'], 'Era già così')
            ? '. ' . rtrim((string) $esito['messaggio'], '.') : '');
        $risultato = RegistroModifiche::durante(GestiDelRegistro::dellaSala($g->assemblea, $conLeConseguenze, $g->segnatoIl), function () use ($conflitto, $scelta, $g) {
            $risultato = ['versione' => null, 'messaggio' => null];
            if ($scelta === 'questa') {
                $rimesso = new GestoInArrivo($g->assemblea, (string) $conflitto->gesto, (string) $conflitto->tipo, (array) $conflitto->dati, $conflitto->base,
                    CarbonImmutable::instance($conflitto->segnato_il), $g->utente, true);
                $risultato = $this->applica($rimesso);
            }
            $conflitto->update(['scelto_il' => now(), 'scelto_da' => $g->utente->id,
                'esito' => $scelta === 'questa' ? EsitoDelGesto::APPLICATO->value : EsitoDelGesto::IN_CONFLITTO->value]);

            return $risultato;
        });

        return $risultato;
    }

    /** Un gesto: il numero, l'ora, poi il servizio nella sua transazione. @param array<string, mixed> $gesto @return array<string, mixed> */
    private function uno(Assemblea $assemblea, string $dispositivo, array $gesto, User $utente): array
    {
        $numero = strtolower((string) $gesto['gesto']);
        $tipo = (string) $gesto['tipo'];
        $risposta = ['gesto' => $numero, 'tipo' => $tipo, 'esito' => null, 'messaggio' => null, 'versione' => null, 'conflitto' => null];
        $gia = GestoDellaSala::query()->where('gesto', $numero)->first();
        if ($gia !== null) {
            return $this->doppio($assemblea, $gia, $risposta);
        }
        $segnato = CarbonImmutable::parse((string) $gesto['segnato_il'])->utc();
        $orologio = isset($gesto['orologio']) && $gesto['orologio'] !== null ? CarbonImmutable::parse((string) $gesto['orologio'])->utc() : null;
        $adesso = CarbonImmutable::now()->utc();
        $base = isset($gesto['base']) && $gesto['base'] !== null ? (int) $gesto['base'] : null;
        $dati = (array) ($gesto['dati'] ?? []);
        $riga = ['assemblea_id' => $assemblea->id, 'gesto' => $numero, 'dispositivo' => $dispositivo, 'tipo' => $tipo, 'dati' => $dati, 'base' => $base,
            'segnato_il' => $segnato, 'orologio' => $orologio, 'arrivato_il' => $adesso, 'utente_id' => $utente->id];

        if ($segnato->gt($adesso->addSeconds(self::SECONDI_AVANTI))) {
            $fuso = (string) config('app.user_timezone', 'Europe/Rome');

            return $this->scritto($riga, $risposta, EsitoDelGesto::RIFIUTATO, 'L\'ora del gesto (' . $segnato->setTimezone($fuso)->format('H:i')
                . ') è avanti rispetto al server (' . $adesso->setTimezone($fuso)->format('H:i') . '): controlla l\'orologio di questo dispositivo.');
        }
        $g = new GestoInArrivo($assemblea, $numero, $tipo, $dati, $base, $segnato, $utente);
        try {
            return DB::transaction(function () use ($riga, $risposta, $g) {
                $scritta = GestoDellaSala::create($riga + ['esito' => EsitoDelGesto::APPLICATO->value]);
                $esito = $this->applica($g);
                $scritta->update(['messaggio' => self::taglia($esito['messaggio'] ?? null)]);

                return array_merge($risposta, $esito, ['esito' => EsitoDelGesto::APPLICATO->value]);
            });
        } catch (DueVersioni $e) {
            return $this->scritto($riga, $risposta, EsitoDelGesto::IN_CONFLITTO, 'Due versioni: scegli quale tenere.',
                ['questa' => $e->questa, 'quella' => $e->quella, 'su' => $e->su] + self::diAdesso($assemblea, $e->diAdesso));
        } catch (ValidationException $e) {
            return $this->scritto($riga, $risposta, EsitoDelGesto::RIFIUTATO, (string) collect($e->errors())->flatten()->first());
        } catch (QueryException $e) {
            // Lo stesso numero scritto intanto da un'altra richiesta: questa torna indietro, e il gesto conta una volta.
            $gia = GestoDellaSala::query()->where('gesto', $numero)->first();
            if ($gia !== null) {
                return $this->doppio($assemblea, $gia, $risposta);
            }

            throw $e;
        }
    }

    /** Applica un gesto con il suo servizio. @return array<string, mixed> */
    private function applica(GestoInArrivo $g): array
    {
        $g->assemblea->refresh();
        if ($g->tipo === 'scegli') {
            return $this->scegli($g);
        }
        $voce = self::TIPI[$g->tipo] ?? throw ValidationException::withMessages(['gesto' => 'Un gesto che la sala non conosce: «' . $g->tipo . '».']);
        [$classe, $metodo] = $voce;

        return app($classe)->{$metodo}($g);
    }

    /**
     * Un gesto rifiutato o in conflitto: la riga del libro dei gesti si scrive lo stesso, così lo stesso numero dopo risponde «doppio».
     *
     * @param array<string, mixed> $riga
     * @param array<string, mixed> $risposta
     * @param ?array<string, mixed> $conflitto
     * @return array<string, mixed>
     */
    private function scritto(array $riga, array $risposta, EsitoDelGesto $esito, string $messaggio, ?array $conflitto = null): array
    {
        try {
            GestoDellaSala::create($riga + ['esito' => $esito->value, 'messaggio' => self::taglia($messaggio), 'conflitto' => $conflitto]);
        } catch (QueryException) {
            // Arrivato intanto da un'altra richiesta: vale quello.
        }

        return array_merge($risposta, ['esito' => $esito->value, 'messaggio' => $messaggio,
            'conflitto' => $conflitto === null ? null : array_diff_key($conflitto, ['su' => true])]);
    }

    /**
     * Chi ha fatto la versione di adesso, nelle due versioni: il dispositivo e l'ora del gesto («Dal tablet di Marco Galli, segnato alle 19:44»). Niente se il
     * gesto non si trova (una versione scritta prima del libro dei gesti).
     *
     * @return array{quella_dispositivo?: string, quella_segnato_il?: string}
     */
    private static function diAdesso(Assemblea $assemblea, ?string $numero): array
    {
        $gesto = $numero === null ? null : GestoDellaSala::query()->where('assemblea_id', $assemblea->id)->where('gesto', strtolower($numero))->first();

        return $gesto === null ? [] : ['quella_dispositivo' => (string) $gesto->dispositivo, 'quella_segnato_il' => $gesto->segnato_il->copy()->utc()->toIso8601ZuluString()];
    }

    /** «Doppio»: il gesto era già arrivato, e conta una volta. @param array<string, mixed> $risposta @return array<string, mixed> */
    private function doppio(Assemblea $assemblea, GestoDellaSala $gia, array $risposta): array
    {
        if ((int) $gia->assemblea_id !== (int) $assemblea->id) {
            return array_merge($risposta, ['esito' => EsitoDelGesto::RIFIUTATO->value, 'messaggio' => 'Questo numero di gesto è già usato.']);
        }
        $alle = $gia->arrivato_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i');

        return array_merge($risposta, self::numeriDellaPrimaVolta($gia), ['esito' => EsitoDelGesto::DOPPIO->value,
            'messaggio' => 'Questo gesto era già arrivato alle ' . $alle . ': conta una volta.', 'esito_di_allora' => $gia->esito?->value]);
    }

    /**
     * I numeri che il gesto ha dato la prima volta, per il dispositivo che non ha avuto la risposta (il computer spento a metà invio): la votazione aperta da
     * «Si vota» o da «Trattato», la delega, la dichiarazione. Si ritrovano dal numero del gesto, scritto nella riga che il gesto ha creato.
     *
     * @return array<string, int>
     */
    private static function numeriDellaPrimaVolta(GestoDellaSala $gia): array
    {
        if ($gia->esito !== EsitoDelGesto::APPLICATO) {
            return [];
        }
        $numero = (string) $gia->gesto;
        $id = match ((string) $gia->tipo) {
            'si_vota', 'trattato' => ['votazione_id', VotazioneDelPunto::query()->where('gesto', $numero)->value('id')],
            'delega' => ['delega_id', DelegaDellAssemblea::query()->where('assemblea_id', $gia->assemblea_id)->where('gesto', $numero)->value('id')],
            'dichiarazione' => ['dichiarazione_id', DichiarazioneAVerbale::query()->where('gesto', $numero)->value('id')],
            default => null,
        };

        return $id === null || $id[1] === null ? [] : [$id[0] => (int) $id[1]];
    }

    private static function taglia(?string $testo): ?string
    {
        return $testo === null ? null : mb_substr($testo, 0, 500);
    }
}
