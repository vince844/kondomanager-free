<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Immobile;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * I piani che chiederebbero cifre diverse dopo una correzione della titolarità di un'unità. Sola lettura: non scrive niente.
 *
 * Per ogni piano generato di un esercizio aperto del condominio (non i piani degli addebiti a una persona), il motore del riparto rifà il calcolo in sola
 * lettura con la titolarità come è scritta oggi e con la competenza che userebbe «Ricalcola» ({@see CompetenzaDelPiano::perPiano()}), e lo confronta con
 * le quote scritte nel piano: la spesa di ogni quota ({@see RataQuote::componenti()}), senza i saldi, che la correzione non cambia. Un piano entra
 * nell'elenco se una persona dell'unità, o il totale del piano, cambiano.
 *
 * Per ogni piano la strada: in bozza «Ricalcola»; emesso senza incassi, annullare le emissioni, ricalcolare ed emettere di nuovo; con incassi, un addebito
 * individuale per chi paga di più (gli incassi registrati non si toccano). Il programma non fa niente da solo.
 */
final class PianiCheCambiano
{
    public const BOZZA = 'bozza';
    public const EMESSO = 'emesso';
    public const CON_INCASSI = 'con_incassi';

    public function __construct(private readonly CompetenzaDelPiano $competenza) {}

    /**
     * @return list<array{piano_rate_id:int, nome:string, esercizio_id:int, stato:string, righe:list<array{anagrafica_id:int, nome:string, prima:int, dopo:int, differenza:int}>,
     *     totale:array{prima:int, dopo:int, differenza:int}, addebiti:list<array{anagrafica_id:int, nome:string, importo:int}>}>
     */
    public function perUnita(Immobile $immobile): array
    {
        $esito = [];
        foreach ($this->pianiDaLeggere((int) $immobile->condominio_id) as [$piano, $esercizio]) {
            try {
                $riga = $this->confronta($piano, $esercizio, (int) $immobile->id);
            } catch (\Throwable $e) {
                // Un piano che il motore non sa rileggere (dati di prima, una delibera che manca) non ferma il salvataggio né gli altri piani.
                Log::warning('PianiCheCambiano: piano non riletto', ['piano_rate_id' => $piano->id, 'error' => $e->getMessage()]);

                continue;
            }
            if ($riga !== null) {
                $esito[] = $riga;
            }
        }

        return $esito;
    }

    /** I piani generati degli esercizi aperti del condominio, con il loro esercizio. @return list<array{0: PianoRate, 1: \App\Models\Esercizio}> */
    private function pianiDaLeggere(int $condominioId): array
    {
        $piani = [];
        foreach (PianoRate::where('condominio_id', $condominioId)->whereHas('rate')->with('gestione')->orderBy('id')->get() as $piano) {
            if ($piano->eDegliAddebiti() || $piano->gestione === null) {
                continue;
            }
            $esercizio = $this->competenza->esercizioDelPiano($piano);
            if ($esercizio === null || $esercizio->stato !== 'aperto') {
                continue;
            }
            $piani[] = [$piano, $esercizio];
        }

        return $piani;
    }

    private function confronta(PianoRate $piano, \App\Models\Esercizio $esercizio, int $immobileId): ?array
    {
        // Le quote scritte: la spesa di ogni quota, per persona e unità.
        $prima = [];
        $quote = RataQuote::query()->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
            ->whereNotNull('rate_quote.anagrafica_id')
            ->get(['rate_quote.id', 'rate_quote.anagrafica_id', 'rate_quote.immobile_id', 'rate_quote.importo', 'rate_quote.regole_calcolo']);
        foreach ($quote as $q) {
            $prima[(int) $q->anagrafica_id][(int) $q->immobile_id] = ($prima[(int) $q->anagrafica_id][(int) $q->immobile_id] ?? 0) + $q->componenti()['spesa'];
        }

        // Il calcolo di oggi: lo stesso motore e la stessa competenza di «Ricalcola», in sola lettura.
        $motore = app(CalcoloQuoteService::class);
        $periodo = $this->competenza->perPiano($piano, $esercizio);
        $dopo = $piano->tipo === 'straordinario' && $piano->fatture()->exists()
            ? $motore->calcolaDaFattureStraordinarie($piano, $periodo, soloLettura: true)
            // Il piano di soli saldi si rilegge di soli saldi, come al suo ricalcolo: le voci libere nate dopo di lui non sono sue.
            : $motore->calcolaPerGestione($piano->gestione, $piano, soloLettura: true, periodo: $periodo,
                diSoliSaldi: $piano->tipo !== 'straordinario' && $piano->eDiSoliSaldi());

        $somma = fn (array $per) => (int) array_sum(array_map('array_sum', $per));
        $totPrima = $somma($prima);
        $totDopo = $somma($dopo);

        // Le persone dell'unità, prima o dopo; e chi, fuori dall'unità, cambia anche lui.
        $persone = [];
        foreach ([$prima, $dopo] as $per) {
            foreach ($per as $aid => $unita) {
                if (array_key_exists($immobileId, $unita)) {
                    $persone[(int) $aid] = true;
                }
            }
        }
        $tutte = array_unique([...array_keys($prima), ...array_keys($dopo)]);
        foreach ($tutte as $aid) {
            if (array_sum($prima[$aid] ?? []) !== array_sum($dopo[$aid] ?? [])) {
                $persone[(int) $aid] = true;
            }
        }

        $cambia = $totPrima !== $totDopo;
        $righe = [];
        foreach (array_keys($persone) as $aid) {
            // Per le persone dell'unità la cifra sull'unità; per le altre il loro totale nel piano.
            $sullUnita = array_key_exists($immobileId, $prima[$aid] ?? []) || array_key_exists($immobileId, $dopo[$aid] ?? []);
            $p = $sullUnita ? (int) ($prima[$aid][$immobileId] ?? 0) : (int) array_sum($prima[$aid] ?? []);
            $d = $sullUnita ? (int) ($dopo[$aid][$immobileId] ?? 0) : (int) array_sum($dopo[$aid] ?? []);
            $cambia = $cambia || $p !== $d;
            $righe[] = ['anagrafica_id' => (int) $aid, 'prima' => $p, 'dopo' => $d, 'differenza' => $d - $p];
        }
        if (! $cambia) {
            return null;
        }

        $nomi = Anagrafica::whereIn('id', array_column($righe, 'anagrafica_id') ?: [0])->pluck('nome', 'id')->all();
        $righe = array_map(fn ($r) => ['anagrafica_id' => $r['anagrafica_id'], 'nome' => (string) ($nomi[$r['anagrafica_id']] ?? '')] + $r, $righe);
        usort($righe, fn ($a, $b) => [-abs($a['differenza']), mb_strtolower($a['nome'])] <=> [-abs($b['differenza']), mb_strtolower($b['nome'])]);

        $stato = $this->stato($piano);

        return [
            'piano_rate_id' => (int) $piano->id,
            'nome' => (string) $piano->nome,
            'esercizio_id' => (int) $esercizio->id,
            'stato' => $stato,
            'righe' => $righe,
            'totale' => ['prima' => $totPrima, 'dopo' => $totDopo, 'differenza' => $totDopo - $totPrima],
            // Con incassi: un addebito individuale per chi paga di più.
            'addebiti' => $stato === self::CON_INCASSI
                ? array_values(array_map(fn ($r) => ['anagrafica_id' => $r['anagrafica_id'], 'nome' => $r['nome'], 'importo' => $r['differenza']], array_filter($righe, fn ($r) => $r['differenza'] > 0)))
                : [],
        ];
    }

    /** In bozza (nessuna rata emessa), emesso senza incassi, o con incassi (una quota del piano ha un versato). */
    private function stato(PianoRate $piano): string
    {
        $rate = DB::table('rate')->where('piano_rate_id', $piano->id);
        if (! (clone $rate)->where('stato', 'emessa')->exists()) {
            return self::BOZZA;
        }
        $conIncassi = DB::table('rate_quote')->whereIn('rata_id', (clone $rate)->select('id'))->where('importo_pagato', '>', 0)->exists();

        return $conIncassi ? self::CON_INCASSI : self::EMESSO;
    }
}
