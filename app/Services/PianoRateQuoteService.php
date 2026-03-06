<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use Illuminate\Support\Collection;

class PianoRateQuoteService
{
    /**
     * Helper privato: Scansiona il piano per capire se include saldi.
     * Non si ferma al primo record, ma cerca attivamente un utilizzo.
     */
    private function determinaSePianoUsaSaldi(PianoRate $pianoRate): bool
    {
        // Ottimizzazione: prendiamo un campione di quote (es. 50) per non scansionare tutto il DB
        $quoteCampione = $pianoRate->rate()
            ->join('rate_quote', 'rate.id', '=', 'rate_quote.rata_id')
            ->whereNotNull('rate_quote.regole_calcolo')
            ->take(50) 
            ->pluck('rate_quote.regole_calcolo');

        foreach ($quoteCampione as $json) {
            $snapshot = json_decode($json, true);
            // Compatibilità V1.9: Controlla la nuova chiave 'importi'
            if (isset($snapshot['importi']['saldo_usato']) && $snapshot['importi']['saldo_usato'] != 0) {
                return true;
            }
            // Compatibilità Legacy (<= V1.8.x): Controlla la vecchia chiave 'audit'
            if (isset($snapshot['audit']['saldo_usato']) && $snapshot['audit']['saldo_usato'] != 0) {
                return true;
            }
        }
        
        return false;
    }

    public function quotePerAnagrafica(PianoRate $pianoRate): Collection
    {
        $esercizio = $pianoRate->gestione->esercizi()->wherePivot('attiva', true)->first() 
                     ?? $pianoRate->gestione->esercizi()->first();

        // 1. Verifica se dobbiamo mostrare i saldi
        $pianoUsaSaldi = $this->determinaSePianoUsaSaldi($pianoRate);

        return $pianoRate->rate
            ->flatMap->rateQuote
            ->groupBy('anagrafica_id')
            ->map(function ($quotes) use ($pianoRate, $esercizio, $pianoUsaSaldi) {

                $anagrafica = $quotes->first()->anagrafica;
                
                // RECUPERO SALDO (Manteniamo per visualizzazioni future/legacy, ma non guida più la UI)
                $saldoIniziale = 0;
                if ($pianoUsaSaldi && $esercizio) {
                    $saldoRecord = Saldo::where('esercizio_id', $esercizio->id)
                        ->where('condominio_id', $pianoRate->condominio_id)
                        ->where('anagrafica_id', $anagrafica->id)
                        ->sum('saldo_iniziale');
                    $saldoIniziale = (int) $saldoRecord;
                }

                $rate = $quotes
                    ->groupBy(fn($q) => $q->rata->numero_rata)
                    ->map(function ($q) {
                        $rata = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagato  = $q->sum('importo_pagato');
                        $residuo = max(0, $importo - $pagato);
                        
                        $stato = 'da_pagare';
                        if ($q->first()->stato === 'annullata') $stato = 'annullata';
                        elseif ($importo < 0) $stato = 'credito';
                        elseif ($pagato >= $importo && $importo > 0) $stato = 'pagata';
                        elseif ($pagato > 0 && $pagato < $importo) $stato = 'parzialmente_pagata';

                        $dataPagamento = $q->whereNotNull('data_pagamento')
                                           ->sortByDesc('data_pagamento')
                                           ->first()
                                           ?->data_pagamento;

                        // --- MODIFICA CHIRURGICA V1.9: APERTURA JSON ---
                        $dettaglioQuote = $q->map(function ($quota) {
                            $componenteSpesa = $quota->importo;
                            $componenteSaldo = 0;

                            if (!empty($quota->regole_calcolo)) {
                                $meta = json_decode($quota->regole_calcolo, true);
                                // Leggiamo il nuovo standard (V1.9) oppure il vecchio (V1.8), o fallbacchiamo all'importo totale
                                $componenteSpesa = $meta['importi']['quota_pura_gestione'] ?? $meta['audit']['quota_pura'] ?? $quota->importo;
                                $componenteSaldo = $meta['importi']['saldo_usato'] ?? $meta['audit']['saldo_usato'] ?? 0;
                            }

                            return [
                                'id'               => $quota->id,
                                'importo'          => $quota->importo,
                                'residuo'          => max(0, $quota->importo - $quota->importo_pagato),
                                'componente_spesa' => $componenteSpesa,
                                'componente_saldo' => $componenteSaldo,
                            ];
                        })->values()->toArray();
                        // ---------------------------------------------

                        return [
                            'numero'          => $rata->numero_rata,
                            'scadenza'        => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'         => $importo,
                            'importo_pagato'  => $pagato,
                            'residuo'         => $residuo, 
                            'stato'           => $stato,
                            'data_pagamento'  => $dataPagamento ? $dataPagamento->format('Y-m-d') : null,
                            'dettaglio_quote' => $dettaglioQuote, // INIEZIONE CHIAVI PIATTE
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
                    'saldo_iniziale' => $saldoIniziale,
                    'rate' => $rate,
                ];
            })
            ->values();
    }

    public function quotePerImmobile(PianoRate $pianoRate): Collection
    {
        $esercizio = $pianoRate->gestione->esercizi()->wherePivot('attiva', true)->first() 
                     ?? $pianoRate->gestione->esercizi()->first();

        $pianoUsaSaldi = $this->determinaSePianoUsaSaldi($pianoRate);

        return $pianoRate->rate
            ->flatMap->rateQuote
            ->whereNotNull('immobile_id')
            ->groupBy('immobile_id')
            ->map(function ($quotes) use ($pianoRate, $esercizio, $pianoUsaSaldi) {

                $immobile = $quotes->first()->immobile;

                $totaleDebiti = 0;
                $totaleCrediti = 0;

                if ($pianoUsaSaldi && $esercizio) {
                    $saldiRecords = Saldo::where('esercizio_id', $esercizio->id)
                        ->where('condominio_id', $pianoRate->condominio_id)
                        ->where('immobile_id', $immobile->id)
                        ->get();

                    foreach ($saldiRecords as $s) {
                        if ($s->saldo_iniziale > 0) {
                            $totaleDebiti += $s->saldo_iniziale;
                        } else {
                            $totaleCrediti += $s->saldo_iniziale;
                        }
                    }
                }

                $rate = $quotes
                    ->groupBy('rata_id')
                    ->map(function ($q) {
                        $rata = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagato = $q->sum('importo_pagato');
                        $residuo = max(0, $importo - $pagato);
                        
                        $stato = 'da_pagare';
                        if ($q->first()->stato === 'annullata') $stato = 'annullata';
                        elseif ($importo < 0) $stato = 'credito';
                        elseif ($pagato >= $importo && $importo > 0) $stato = 'pagata';
                        elseif ($pagato > 0 && $pagato < $importo) $stato = 'parzialmente_pagata';

                        // --- MODIFICA CHIRURGICA V1.9: APERTURA JSON ---
                        $dettaglioQuote = $q->map(function ($quota) {
                            $componenteSpesa = $quota->importo;
                            $componenteSaldo = 0;

                            if (!empty($quota->regole_calcolo)) {
                                $meta = json_decode($quota->regole_calcolo, true);
                                $componenteSpesa = $meta['importi']['quota_pura_gestione'] ?? $meta['audit']['quota_pura'] ?? $quota->importo;
                                $componenteSaldo = $meta['importi']['saldo_usato'] ?? $meta['audit']['saldo_usato'] ?? 0;
                            }

                            return [
                                'id'               => $quota->id,
                                'importo'          => $quota->importo,
                                'residuo'          => max(0, $quota->importo - $quota->importo_pagato),
                                'componente_spesa' => $componenteSpesa,
                                'componente_saldo' => $componenteSaldo,
                            ];
                        })->values()->toArray();
                        // ---------------------------------------------

                        return [
                            'numero'   => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'  => $importo,
                            'importo_pagato' => $pagato,
                            'residuo'         => $residuo, 
                            'stato'          => $stato,
                            'data_pagamento' => $q->sortByDesc('data_pagamento')->first()?->data_pagamento?->format('Y-m-d'),
                            'dettaglio_quote' => $dettaglioQuote, // INIEZIONE CHIAVI PIATTE
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
                    'totale_debiti'  => (int) $totaleDebiti,
                    'totale_crediti' => (int) $totaleCrediti, 
                    'rate' => $rate,
                ];
            })
            ->values();
    }
}