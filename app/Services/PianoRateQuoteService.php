<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use Illuminate\Support\Collection;

class PianoRateQuoteService
{
    /**
     * Helper privato: Scansiona il piano per capire se include saldi.
     */
    private function determinaSePianoUsaSaldi(PianoRate $pianoRate): bool
    {
        $quoteCampione = $pianoRate->rate()
            ->join('rate_quote', 'rate.id', '=', 'rate_quote.rata_id')
            ->whereNotNull('rate_quote.regole_calcolo')
            ->take(50) 
            ->pluck('rate_quote.regole_calcolo');

        foreach ($quoteCampione as $json) {
            // Qui json_decode serve ancora perché pluck() su query builder restituisce stringhe grezze
            $snapshot = is_array($json) ? $json : json_decode($json, true);
            if (isset($snapshot['importi']['saldo_usato']) && $snapshot['importi']['saldo_usato'] != 0) {
                return true;
            }
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

        $pianoUsaSaldi = $this->determinaSePianoUsaSaldi($pianoRate);

        return $pianoRate->rate
            ->flatMap->rateQuote
            ->groupBy('anagrafica_id')
            ->map(function ($quotes) use ($pianoRate, $esercizio, $pianoUsaSaldi) {

                $anagrafica = $quotes->first()->anagrafica;
                
                $saldoIniziale = 0;
                if ($pianoUsaSaldi && $esercizio) {
                    // La gestione è il filtro che mancava. Senza, con
                    // un'ordinaria e una straordinaria aperte sullo stesso
                    // esercizio l'avviso dell'una stampava anche il pregresso
                    // dell'altra — e nel verso opposto, un credito altrove
                    // abbassava il dovuto. È l'asse su cui filtra da sempre
                    // GenerateSaldiAction: la stampa deve mostrare ciò che il
                    // piano ha davvero assorbito, non un'altra somma.
                    $saldoRecord = Saldo::where('esercizio_id', $esercizio->id)
                        ->where('condominio_id', $pianoRate->condominio_id)
                        ->where('gestione_id', $pianoRate->gestione_id)
                        ->whereNull('fornitore_id')
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

                        // --- FIX V1.9: Rimosso json_decode (Laravel lo casta già in array) ---

                        $dettaglioQuote = $q->map(function ($quota) {
                            $componenteSpesa = $quota->importo;
                            $componenteSaldo = 0;

                            $meta = $quota->regole_calcolo;

                            if (!empty($meta) && is_array($meta)) {
                                $componenteSpesa = $meta['importi']['quota_pura_gestione'] ?? $meta['audit']['quota_pura'] ?? $quota->importo;
                                $componenteSaldo = $meta['importi']['saldo_usato'] ?? $meta['audit']['saldo_usato'] ?? 0;
                            }

                            // FIX: Esporre immobile e tipo per lo "Spaccato Scontrino" nel frontend
                            return [
                                'id'               => $quota->id,
                                'immobile_id'      => $quota->immobile_id,
                                'immobile_nome'    => $quota->immobile ? $quota->immobile->nome . ($quota->immobile->interno ? ' (Int. ' . $quota->immobile->interno . ')' : '') : 'Condominio',
                                'tipo'             => $quota->tipo, // 'ordinaria' o 'saldo_iniziale'
                                'importo'          => $quota->importo,
                                'residuo'          => max(0, $quota->importo - $quota->importo_pagato),
                                'componente_spesa' => $componenteSpesa,
                                'componente_saldo' => $componenteSaldo,
                            ];
                        })->values()->toArray();
                        
                        return [
                            'numero'          => $rata->numero_rata,
                            'scadenza'        => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'         => $importo,
                            'importo_pagato'  => $pagato,
                            'residuo'         => $residuo, 
                            'stato'           => $stato,
                            'data_pagamento'  => $dataPagamento ? $dataPagamento->format('Y-m-d') : null,
                            'dettaglio_quote' => $dettaglioQuote,
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
                    // Stesso filtro di quotePerAnagrafica, e per la stessa
                    // ragione: il totale stampato per l'unità deve essere
                    // quello della gestione del piano, non di tutte insieme.
                    $saldiRecords = Saldo::where('esercizio_id', $esercizio->id)
                        ->where('condominio_id', $pianoRate->condominio_id)
                        ->where('gestione_id', $pianoRate->gestione_id)
                        ->whereNull('fornitore_id')
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

                        // --- FIX V1.9: Rimosso json_decode ---
                        $dettaglioQuote = $q->map(function ($quota) {
                            $componenteSpesa = $quota->importo;
                            $componenteSaldo = 0;

                            $meta = $quota->regole_calcolo;

                            if (!empty($meta) && is_array($meta)) {
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

                        return [
                            'numero'   => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'  => $importo,
                            'importo_pagato' => $pagato,
                            'residuo'         => $residuo, 
                            'stato'          => $stato,
                            'data_pagamento' => $q->sortByDesc('data_pagamento')->first()?->data_pagamento?->format('Y-m-d'),
                            'dettaglio_quote' => $dettaglioQuote,
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