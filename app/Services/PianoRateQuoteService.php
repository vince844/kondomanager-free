<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
use App\Models\Saldo; // <--- Importante
use Illuminate\Support\Collection;

class PianoRateQuoteService
{
    public function quotePerAnagrafica(PianoRate $pianoRate): Collection
    {
        // Otteniamo l'esercizio per cercare i saldi
        $esercizio = $pianoRate->gestione->esercizi()->wherePivot('attiva', true)->first() 
                     ?? $pianoRate->gestione->esercizi()->first();

        return $pianoRate->rate
            ->flatMap->rateQuote
            ->groupBy('anagrafica_id')
            ->map(function ($quotes) use ($pianoRate, $esercizio) { // <--- Passiamo le variabili

                $anagrafica = $quotes->first()->anagrafica;
                
                // --- RECUPERO SALDO INIZIALE ---
                $saldoIniziale = 0;
                if ($esercizio) {
                    $saldoRecord = Saldo::where('esercizio_id', $esercizio->id)
                        ->where('condominio_id', $pianoRate->condominio_id)
                        ->where('anagrafica_id', $anagrafica->id)
                        ->sum('saldo_iniziale'); // Usa sum per sicurezza nel caso ci siano duplicati strani
                    $saldoIniziale = (int) $saldoRecord;
                }
                // -------------------------------

                $rate = $quotes
                    ->groupBy(fn($q) => $q->rata->numero_rata)
                    ->map(function ($q) {
                        $rata    = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagato  = $q->sum('importo_pagato');
                        
                        $stato = 'da_pagare';
                        if ($q->first()->stato === 'annullata') {
                            $stato = 'annullata';
                        } elseif ($importo < 0) {
                            $stato = 'credito';
                        } elseif ($pagato >= $importo && $importo > 0) {
                            $stato = 'pagata';
                        } elseif ($pagato > 0 && $pagato < $importo) {
                            $stato = 'parzialmente_pagata';
                        }

                        $dataPagamento = $q->whereNotNull('data_pagamento')
                                           ->sortByDesc('data_pagamento')
                                           ->first()
                                           ?->data_pagamento;

                        return [
                            'numero'   => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'  => $importo,
                            'importo_pagato' => $pagato,
                            'stato'          => $stato,
                            'data_pagamento' => $dataPagamento ? $dataPagamento->format('Y-m-d') : null,
                        ];
                    })
                    ->sortBy('numero')
                    ->values();

                return [
                    'anagrafica' => [
                        'id'        => $anagrafica->id,
                        'nome'      => $anagrafica->nome,
                        'indirizzo' => $anagrafica->indirizzo,
                    ],
                    'saldo_iniziale' => $saldoIniziale, // <--- CAMPO AGGIUNTO AL JSON
                    'rate' => $rate,
                ];
            })
            ->values();
    }

    // Fai la stessa logica per quotePerImmobile se vuoi vedere il saldo anche lì
    public function quotePerImmobile(PianoRate $pianoRate): Collection
    {
         $esercizio = $pianoRate->gestione->esercizi()->wherePivot('attiva', true)->first() 
         ?? $pianoRate->gestione->esercizi()->first();

        return $pianoRate->rate
            ->flatMap->rateQuote
            ->whereNotNull('immobile_id')
            ->groupBy('immobile_id')
            ->map(function ($quotes) use ($pianoRate, $esercizio) {

                $immobile = $quotes->first()->immobile;

                // --- MODIFICA QUI: RECUPERO SALDI SEPARATI ---
                $totaleDebiti = 0;
                $totaleCrediti = 0;

                if ($esercizio) {
                    // Prendiamo tutti i record di saldo per questo immobile in questo esercizio
                    $saldiRecords = Saldo::where('esercizio_id', $esercizio->id)
                        ->where('condominio_id', $pianoRate->condominio_id)
                        ->where('immobile_id', $immobile->id)
                        ->get();

                    // Separiamo i positivi (debiti) dai negativi (crediti)
                    foreach ($saldiRecords as $s) {
                        if ($s->saldo_iniziale > 0) {
                            $totaleDebiti += $s->saldo_iniziale;
                        } else {
                            $totaleCrediti += $s->saldo_iniziale;
                        }
                    }
                }
                // ---------------------------------------------

                $rate = $quotes
                    ->groupBy('rata_id')
                    // ... (resto del codice uguale per il calcolo rate) ...
                    ->map(function ($q) {
                        // ... codice map esistente ...
                        $rata = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagato = $q->sum('importo_pagato');
                        
                        // ... calcolo stato ...
                        $stato = 'da_pagare'; // (Logica standard che hai già)
                        if ($q->first()->stato === 'annullata') $stato = 'annullata';
                        elseif ($importo < 0) $stato = 'credito';
                        elseif ($pagato >= $importo && $importo > 0) $stato = 'pagata';
                        elseif ($pagato > 0 && $pagato < $importo) $stato = 'parzialmente_pagata';

                        return [
                            'numero'   => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'  => $importo,
                            'importo_pagato' => $pagato,
                            'stato'          => $stato,
                            'data_pagamento' => $q->sortByDesc('data_pagamento')->first()?->data_pagamento?->format('Y-m-d'),
                        ];
                    })
                    ->sortBy('numero')
                    ->values();

                return [
                    'immobile' => [
                        'id'         => $immobile->id,
                        'nome'       => $immobile->nome ?? 'Sconosciuto',
                        'interno'    => $immobile->interno,
                        'piano'      => $immobile->piano,
                        'superficie' => $immobile->superficie,
                    ],
                    // PASSIAMO I DUE VALORI SEPARATI
                    'totale_debiti'  => (int) $totaleDebiti,
                    'totale_crediti' => (int) $totaleCrediti, 
                    'rate' => $rate,
                ];
            })
            ->values();
    }
}