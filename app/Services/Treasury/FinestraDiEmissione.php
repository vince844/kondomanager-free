<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Treasury;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * I dati della finestra «Emetti le rate», che si apre dall'azione delle rate da emettere nel cassetto della tesoreria: i piani del condominio con rate non
 * ancora emesse, e di ogni piano tutte le rate non emesse con la scadenza, la cifra e i condòmini.
 *
 * Le regole sono quelle dell'azione, nello stesso posto ({@see DatiDelleAzioni::rateNonEmesse()}, {@see DatiDelleAzioni::cifrePerRata()}): i piani approvati e
 * non il piano degli addebiti, «emessa» vuol dire a giornale, la cifra di una rata è per persona la sua parte da pagare, mai sotto zero. La finestra elenca
 * tutte le rate non emesse e spunta di serie quelle con la scadenza fino a `fino` (oggi più 30 giorni, la finestra della tesoreria), le già scadute comprese,
 * e con la cifra sopra zero: aperta, conta le stesse rate e la stessa cifra dell'azione, che salta una rata senza denaro (già versata da tutti, o fatta solo
 * di crediti). Quella rata resta nell'elenco della finestra, e si spunta a mano.
 *
 * I condòmini: per rata quanti sono (le persone con una quota della rata), e per piano quanti sono su tutte le sue rate non emesse. Le persone di una rata si
 * mandano una per una (`chi`) solo quando la rata non le ha tutte: il riepilogo della finestra conta le persone delle rate spuntate senza doppioni, e una rata
 * con tutte le persone del piano le porta già tutte. Di solito è così per ogni rata, e a 3.000 persone × 12 rate la risposta resta piccola.
 *
 * L'ordine: i piani per la prima scadenza da emettere, come le azioni; le rate di un piano per scadenza e numero.
 */
final class FinestraDiEmissione
{
    /**
     * @return array{oggi: string, fino: string, piani: list<array{pianoId:int, piano:string, esercizioId:?int, persone:int,
     *     rate: list<array{rataId:int, numero:int, scadenza:string, cifra:int, persone:int, chi:?list<int>}>}>}
     */
    public function perCondominio(Condominio $condominio, ?CarbonImmutable $oggi = null, int $giorni = 30): array
    {
        $oggi ??= DateHelper::oggiUtenteImmutable();
        $risposta = ['oggi' => $oggi->toDateString(), 'fino' => $oggi->addDays($giorni)->toDateString(), 'piani' => []];

        // Senza l'esercizio salvato sul piano, la pagina del piano si apre dall'esercizio della sua gestione: il più recente.
        $esercizioDellaGestione = DB::table('esercizio_gestione as eg')->whereColumn('eg.gestione_id', 'p.gestione_id')->selectRaw('MAX(eg.esercizio_id)');
        $rate = DatiDelleAzioni::rateNonEmesse((int) $condominio->id)
            ->orderBy('r.data_scadenza')->orderBy('p.id')->orderBy('r.numero_rata')
            ->select(['r.id', 'r.numero_rata', 'r.data_scadenza', 'p.id as piano_id', 'p.nome as piano', 'p.esercizio_id'])
            ->selectSub($esercizioDellaGestione, 'esercizio_della_gestione')
            ->get();
        if ($rate->isEmpty()) {
            return $risposta;
        }

        $ids = $rate->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cifre = DatiDelleAzioni::cifrePerRata($ids);
        $perRata = DB::table('rate_quote')->whereIn('rata_id', $ids)->whereNotNull('anagrafica_id')
            ->groupBy('rata_id')->selectRaw('rata_id, COUNT(DISTINCT anagrafica_id) as quante')->pluck('quante', 'rata_id');
        $perPiano = DB::table('rate_quote as q')->join('rate as r', 'r.id', '=', 'q.rata_id')->whereIn('q.rata_id', $ids)->whereNotNull('q.anagrafica_id')
            ->groupBy('r.piano_rate_id')->selectRaw('r.piano_rate_id as piano_id, COUNT(DISTINCT q.anagrafica_id) as quante')->pluck('quante', 'piano_id');

        // Le persone una per una, solo per le rate che non hanno tutte le persone del loro piano.
        $parziali = $rate->filter(fn ($r) => (int) ($perRata[$r->id] ?? 0) < (int) ($perPiano[$r->piano_id] ?? 0))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chi = array_fill_keys($parziali, []);
        if ($parziali !== []) {
            $righe = DB::table('rate_quote')->whereIn('rata_id', $parziali)->whereNotNull('anagrafica_id')->distinct()
                ->orderBy('rata_id')->orderBy('anagrafica_id')->get(['rata_id', 'anagrafica_id']);
            foreach ($righe as $q) {
                $chi[(int) $q->rata_id][] = (int) $q->anagrafica_id;
            }
        }

        $piani = [];
        foreach ($rate as $r) {
            $piano = (int) $r->piano_id;
            $esercizio = $r->esercizio_id ?? $r->esercizio_della_gestione;
            $piani[$piano] ??= ['pianoId' => $piano, 'piano' => (string) $r->piano, 'esercizioId' => $esercizio !== null ? (int) $esercizio : null,
                'persone' => (int) ($perPiano[$piano] ?? 0), 'rate' => []];
            $piani[$piano]['rate'][] = [
                'rataId' => (int) $r->id,
                'numero' => (int) $r->numero_rata,
                'scadenza' => substr((string) $r->data_scadenza, 0, 10),
                'cifra' => (int) ($cifre[(int) $r->id] ?? 0),
                'persone' => (int) ($perRata[$r->id] ?? 0),
                'chi' => array_key_exists((int) $r->id, $chi) ? $chi[(int) $r->id] : null,
            ];
        }
        $risposta['piani'] = array_values($piani);

        return $risposta;
    }
}
