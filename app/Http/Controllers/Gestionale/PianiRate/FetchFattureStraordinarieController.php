<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Helpers\MoneyHelper;
use App\Services\Gestionale\CarrelloDelloStraordinario;
use App\Services\Gestionale\FatturaPassivaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Il carrello della scheda «Spese fuori preventivo» del nuovo piano rate: le fatture della gestione scelta (Coda 686 (d)) che il piano può mettere a rate,
 * con il residuo. Il calcolo sta in `CarrelloDelloStraordinario`, che dice anche ciò che il carrello non mostra (`FetchFuoriDalCarrelloController`).
 */
class FetchFattureStraordinarieController extends Controller
{
    public function __invoke(Condominio $condominio, Request $request, CarrelloDelloStraordinario $carrello): JsonResponse
    {
        // La gestione e l'esercizio del condominio dell'indirizzo: di un altro condominio non si leggono le fatture.
        $request->validate([
            'gestione_id'  => ['required', 'integer', Rule::exists('gestioni', 'id')->where('condominio_id', $condominio->id)],
            'esercizio_id' => ['required', 'integer', Rule::exists('esercizi', 'id')->where('condominio_id', $condominio->id)],
        ]);

        try {
            $esercizioId = (int) $request->input('esercizio_id');
            $righe = $carrello->righe((int) $condominio->id, (int) $request->input('gestione_id'), $esercizioId,
                $request->filled('piano_rate_id') ? (int) $request->input('piano_rate_id') : null);

            // R2 della Fase 1-bis: una pregressa registrata prima della beta.35 può avere come periodo la data dell'assemblea di quest'anno — la finestra
            // della motivazione la precompilava. Il carrello la segnala con lo stesso confronto della regola di registrazione.
            $inizioEsercizio = DB::table('esercizi')->where('id', $esercizioId)->value('data_inizio');

            $risposta = [];
            foreach ($righe as $f) {
                if ($f['residuo'] <= 0) {
                    continue;
                }
                $conPeriodo = $f['competenza_dal'] !== null && $f['competenza_al'] !== null;
                // Gli importi in euro per la pagina (MoneyHelper::fromCents: 183.00, non 18300).
                $risposta[] = [
                    'id'                    => $f['id'],
                    'fornitore'             => $f['fornitore'],
                    'numero_documento'      => $f['numero_documento'] ?? 'S/N',
                    'data_documento'        => Carbon::parse($f['data_documento'])->format('d/m/Y'),
                    'totale_straordinario'  => MoneyHelper::fromCents($f['totale']),
                    'gia_finanziato'        => MoneyHelper::fromCents($f['gia']),
                    'residuo_da_finanziare' => MoneyHelper::fromCents($f['residuo']),
                    'importo_suggerito'     => MoneyHelper::fromCents($f['residuo']),
                    // Coda 165: la parte al netto delle note collegate, e le note — il carrello le nomina accanto alla fattura.
                    'totale_netto'          => MoneyHelper::fromCents($f['netto']),
                    'note_collegate'        => array_map(fn (array $n) => [
                        'id' => $n['id'],
                        'numero' => $n['numero'],
                        'data' => $n['data'] !== null ? Carbon::parse($n['data'])->format('d/m/Y') : null,
                        'importo' => MoneyHelper::fromCents($n['importo']),
                    ], $f['note']),
                    'selezionata'           => false,
                    // Coda 159: la fattura annullata dello stesso fornitore che un piano che ha incassato chiede ancora (`CarrelloDelloStraordinario`).
                    'avviso_sostituzione'   => $f['avviso_sostituzione'] ?? null,
                    'ha_competenza'         => $conPeriodo,
                    'is_pregresso'          => $f['is_pregresso'],
                    'senza_periodo'         => $f['is_pregresso'] && ! $conPeriodo,
                    // La spesa vecchia senza periodo, non pregressa: sull'ordinaria il riparto la divide sui giorni di quest'anno, anche la parte di una sola
                    // unità (`CalcoloQuoteService::giornoDellaSpesaPersonale`), e il carrello dice come farla pagare a chi era titolare allora.
                    'spesa_vecchia'         => ! $f['is_pregresso'] && ! $conPeriodo && $inizioEsercizio !== null
                        && substr((string) $f['data_documento'], 0, 10) < substr((string) $inizioEsercizio, 0, 10),
                    'periodo_nell_esercizio' => $f['is_pregresso'] && $conPeriodo
                        && FatturaPassivaService::periodoChiusoPrimaDellEsercizio($f['competenza_al'], $inizioEsercizio) === false,
                    'competenza'            => $conPeriodo
                        ? ($f['competenza_dal'] === $f['competenza_al']
                            ? 'deliberata il ' . Carbon::parse($f['competenza_dal'])->format('d/m/Y')
                            : Carbon::parse($f['competenza_dal'])->format('d/m/Y') . ' – ' . Carbon::parse($f['competenza_al'])->format('d/m/Y'))
                        : null,
                ];
            }

            return response()->json($risposta);

        } catch (\Exception $e) {
            Log::error('Errore fetch fatture straordinarie: ' . $e->getMessage());
            return response()->json(['error' => 'Errore interno.'], 500);
        }
    }
}
