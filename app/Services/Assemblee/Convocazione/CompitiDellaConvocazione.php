<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\EventoTipo;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\ChiusuraDeiCompiti;
use App\Services\Gestionale\InboxService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * I compiti della convocazione nell'Action inbox (§4.6 del progetto della tappa 2; decisione 565), con l'assemblea in `meta.context.assemblea_id` (non come
 * `eventable`: gli eventi con l'assemblea come `eventable` sono le voci della sua agenda, che {@see \App\Services\Assemblee\AgendaDellAssemblea} rifà):
 *
 * - **«Spedire la convocazione entro il 03/12»**: nasce con le date, si sposta con loro e con la data delle raccomandate scelta dall'amministratore; il giorno
 *   è quello delle raccomandate se qualcuno da spedire va per raccomandata, altrimenti l'ultimo giorno di ricezione. Si chiude quando tutti i convocati hanno
 *   il loro avviso spedito ({@see ElencoDeiConvocati::spedito()}, la regola del passo «Convocazione»): la lettera stampata non basta. Due giorni
 *   lavorativi prima del suo giorno e la mattina del giorno, il promemoria per email a chi ha il compito ({@see PromemoriaDellaConvocazione}).
 * - **«Ricevute mancanti»**, uno per invio spedito senza esito: nasce **il giorno dopo l'arrivo atteso** (la spedizione più i giorni di posta, con lo stesso
 *   calendario; la PEC il giorno dopo l'invio) e dice l'ultimo giorno di ricezione, perché fino ad allora si può ancora consegnare a mano
 *   (§13 rilievo 13); se l'arrivo atteso è già dopo l'ultimo giorno, esce subito. Si chiude con l'esito.
 * - Un'assemblea annullata, tenuta fuori dal programma o già passata chiude i suoi compiti con la frase. «Nuovo titolare non convocato» è di
 *   {@see NuovoTitolareNonConvocato}.
 *
 * Il giorno dei compiti è scritto come si legge (le 09:00 di Roma, come il memo): `GiornoDelCompitoNelGiornoDiRomaTest`. Ciò che il programma crea da solo
 * non va nel registro delle modifiche (decisione 547 (2)).
 */
class CompitiDellaConvocazione
{
    public const ORA = '09:00';

    public function __construct(private readonly TerminiDellaConvocazione $termini, private readonly ElencoDeiConvocati $elenco) {}

    public function sincronizza(Assemblea $assemblea): void
    {
        $assemblea->refresh();
        $chiusa = $this->perche($assemblea);
        $this->spedire($assemblea, $chiusa);
        $this->ricevute($assemblea, $chiusa);
        if ($chiusa !== null) {
            foreach ($this->aperti($assemblea, EventoTipo::NUOVO_TITOLARE_NON_CONVOCATO) as $c) {
                ChiusuraDeiCompiti::chiudi($c, $chiusa);
            }
        }
        InboxService::clearAdminCache();
    }

    /** Chiude tutti i compiti della convocazione di un'assemblea con la frase: per l'assemblea in preparazione che si elimina. */
    public function chiudiTutti(Assemblea $assemblea, string $frase = 'Chiuso: l\'assemblea è stata eliminata.'): void
    {
        foreach ([EventoTipo::SPEDIRE_CONVOCAZIONE, EventoTipo::RICEVUTE_MANCANTI, EventoTipo::NUOVO_TITOLARE_NON_CONVOCATO] as $tipo) {
            foreach ($this->aperti($assemblea, $tipo) as $c) {
                ChiusuraDeiCompiti::chiudi($c, $frase);
            }
        }
        InboxService::clearAdminCache();
    }

    /** Il perché i compiti si chiudono tutti: l'assemblea non è più da convocare. Null se è aperta. */
    private function perche(Assemblea $assemblea): ?string
    {
        return match (true) {
            $assemblea->stato === StatoAssemblea::ANNULLATA => 'Chiuso: l\'assemblea è annullata.',
            $assemblea->stato === StatoAssemblea::REGISTRATA_A_MANO => 'Chiuso: l\'assemblea è registrata a mano.',
            ! in_array($assemblea->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true) => 'Chiuso: l\'assemblea non è più da convocare.',
            ($prima = $this->termini->primaConvocazione($assemblea)) === null => 'Chiuso: l\'assemblea non ha più la data della prima convocazione.',
            $prima->format('Y-m-d') <= DateHelper::oggiUtente() => 'Chiuso: il giorno della prima convocazione è arrivato.',
            default => null,
        };
    }

    private function spedire(Assemblea $assemblea, ?string $chiusa): void
    {
        $aperti = $this->aperti($assemblea, EventoTipo::SPEDIRE_CONVOCAZIONE);
        if ($chiusa !== null) {
            foreach ($aperti as $c) {
                ChiusuraDeiCompiti::chiudi($c, $chiusa);
            }

            return;
        }
        $daSpedire = $this->daSpedire($assemblea);
        if ($daSpedire === []) {
            foreach ($aperti as $c) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: la convocazione è partita per tutti i convocati.');
            }

            return;
        }
        $conRaccomandata = collect($daSpedire)->contains(fn ($v) => ($v['mezzo']['mezzo'] ?? null) === 'raccomandata');
        // Il giorno scelto, mai uno passato (decisione 665): con la prima convocazione troppo vicina oggi, e il compito lo dice; non risulta scaduto.
        $mezzo = $conRaccomandata ? 'raccomandata' : null;
        $entro = $this->termini->entroPer($assemblea, $mezzo);
        if ($entro === null) {
            return;
        }
        $titolo = 'Spedire la convocazione ' . ConsegneDellaConvocazione::entro($entro)
            . ($this->termini->fuoriDaiCinqueGiorni($assemblea, $mezzo) ? ', fuori dai cinque giorni dell\'art. 66' : '');
        $descrizione = 'Assemblea ' . mb_strtolower($assemblea->nome()) . ': ' . (count($daSpedire) === 1 ? '1 avviso' : count($daSpedire) . ' avvisi') . ' da spedire.';
        $inizio = self::alleNove($entro);
        $compito = $aperti->first();
        if ($compito === null) {
            InboxService::createTask(EventoTipo::SPEDIRE_CONVOCAZIONE, $titolo, $descrizione, $inizio, $assemblea->updated_by ?? $assemblea->created_by,
                (int) $assemblea->condominio_id, ['assemblea_id' => (int) $assemblea->id], self::indirizzo($assemblea),
                ['assemblea_id' => (int) $assemblea->id, 'riga' => $descrizione]);

            return;
        }
        $compito->update(['title' => $titolo, 'description' => $descrizione, 'start_time' => $inizio, 'end_time' => $inizio->copy()->addHour(),
            'meta' => array_merge((array) $compito->meta, ['riga' => $descrizione])]);
        foreach ($aperti->slice(1) as $doppio) {
            ChiusuraDeiCompiti::chiudi($doppio, 'Chiuso: c\'è già il compito della spedizione.');
        }
    }

    /**
     * Gli avvisi ancora da spedire: chi si convoca oggi e non ha il suo avviso spedito ({@see ElencoDeiConvocati::spedito()}). Li contano il compito e il
     * promemoria per email ({@see PromemoriaDellaConvocazione}).
     *
     * @return list<array<string, mixed>>
     */
    public function daSpedire(Assemblea $assemblea): array
    {
        return array_values(array_filter($this->elenco->per($assemblea), fn ($v) => $v['oggi'] && ! ElencoDeiConvocati::spedito($v)));
    }

    private function ricevute(Assemblea $assemblea, ?string $chiusa): void
    {
        $aperti = $this->aperti($assemblea, EventoTipo::RICEVUTE_MANCANTI)->keyBy(fn (Evento $e) => (int) ($e->meta['context']['invio_id'] ?? 0));
        if ($chiusa !== null) {
            foreach ($aperti as $c) {
                ChiusuraDeiCompiti::chiudi($c, $chiusa);
            }

            return;
        }
        $ultimo = $this->termini->ultimoGiorno($assemblea);
        $invii = $this->elenco->invii($assemblea)->groupBy('anagrafica_id')->map(fn ($g) => $g->last());
        $restano = [];
        foreach ($invii as $invio) {
            /** @var RegistroInvio $invio */
            $attesa = $this->arrivoAtteso($invio);
            if ($invio->esito !== null || $attesa === null || $ultimo === null) {
                continue;
            }
            $restano[(int) $invio->id] = true;
            if ($aperti->has((int) $invio->id)) {
                continue;
            }
            $giorno = $attesa->lt($ultimo) ? $attesa->addDay() : CarbonImmutable::parse(DateHelper::oggiUtente());
            $chi = (string) ($invio->dati['persona']['nome'] ?? 'Una persona');
            $titolo = 'Ricevuta mancante: ' . $chi . ', ' . ConsegneDellaConvocazione::entro($ultimo) . ' si può ancora consegnare a mano';
            $riga = 'Assemblea ' . mb_strtolower($assemblea->nome()) . ' · arrivo atteso il ' . $attesa->format('d/m') . ' · ultimo giorno ' . $ultimo->format('d/m');
            InboxService::createTask(EventoTipo::RICEVUTE_MANCANTI, $titolo, $riga, self::alleNove($giorno), $invio->emesso_da === null ? null : (int) $invio->emesso_da,
                (int) $assemblea->condominio_id, ['assemblea_id' => (int) $assemblea->id, 'invio_id' => (int) $invio->id], self::indirizzo($assemblea),
                ['assemblea_id' => (int) $assemblea->id, 'riga' => $riga, 'scadenza' => $ultimo->format('Y-m-d')]);
        }
        foreach ($aperti as $id => $c) {
            if (! isset($restano[$id])) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: l\'esito è registrato.');
            }
        }
    }

    /**
     * Il giorno in cui l'avviso dovrebbe essere arrivato: la raccomandata e il fax spediti con la loro data (il fax lo stesso giorno), la PEC partita il giorno
     * dell'invio. Null se non è spedito: la lettera stampata, anche quella da consegnare a mano, la ricorda il compito della spedizione (la consegna a mano
     * registrata ha già il suo esito).
     */
    public function arrivoAtteso(RegistroInvio $invio): ?CarbonImmutable
    {
        $mezzo = (string) ($invio->dati['mezzo'] ?? '');
        $giornoDellInvio = CarbonImmutable::parse($invio->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'));

        return match (true) {
            $mezzo === 'raccomandata' && $invio->stato === RegistroInvio::STATO_SPEDITA && filled($invio->dati['spedizione']['data'] ?? null)
                => $this->termini->arrivoAtteso((string) $invio->dati['spedizione']['data']),
            $mezzo === 'fax' && $invio->stato === RegistroInvio::STATO_SPEDITA && filled($invio->dati['spedizione']['data'] ?? null)
                => CarbonImmutable::parse((string) $invio->dati['spedizione']['data']),
            $mezzo === 'pec' && $invio->stato === RegistroInvio::STATO_INVIATA => $giornoDellInvio,
            default => null,
        };
    }

    /** @return \Illuminate\Support\Collection<int, Evento> */
    public function aperti(Assemblea $assemblea, EventoTipo $tipo): \Illuminate\Support\Collection
    {
        return Evento::query()->where('meta->type', $tipo->value)->where('meta->context->assemblea_id', (int) $assemblea->id)
            ->where('is_completed', false)->orderBy('id')->get();
    }

    private static function alleNove(CarbonImmutable $giorno): Carbon
    {
        return Carbon::parse($giorno->format('Y-m-d') . ' ' . self::ORA . ':00', (string) config('app.timezone'));
    }

    /** La pagina dell'assemblea sul passo «Convocazione», quando la rotta c'è. */
    public static function indirizzo(Assemblea $assemblea): ?string
    {
        return Route::has('admin.gestionale.assemblee.show')
            ? route('admin.gestionale.assemblee.show', ['condominio' => $assemblea->condominio_id, 'assemblea' => $assemblea->id, 'scheda' => 'convocazione'])
            : null;
    }
}
