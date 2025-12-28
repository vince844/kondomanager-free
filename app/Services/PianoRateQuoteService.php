<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Collection;

class PianoRateQuoteService
{
    /**
     * Returns all quotes grouped by Anagrafica (owner/tenant).
     */
    public function quotePerAnagrafica(PianoRate $pianoRate): Collection
    {
        return $pianoRate->rate
            ->flatMap->rateQuote
            ->groupBy('anagrafica_id')
            ->map(function ($quotes) {

                $anagrafica = $quotes->first()->anagrafica;

                $rate = $quotes
                    ->groupBy(fn($q) => $q->rata->numero_rata)
                    ->map(function ($q) {
                        $rata    = $q->first()->rata;
                        // Sommiamo gli importi (utile se ci sono più quote aggregate, anche se raro per anagrafica)
                        $importo = $q->sum('importo');
                        $pagato  = $q->sum('importo_pagato');
                        
                        // Determiniamo lo stato basandoci sui valori reali
                        // Nota: usiamo i totali calcolati per gestire eventuali aggregazioni
                        $stato = 'da_pagare';
                        
                        // Controllo prioritario sullo stato del DB (es. annullata)
                        if ($q->first()->stato === 'annullata') {
                            $stato = 'annullata';
                        } elseif ($importo < 0) {
                            $stato = 'credito';
                        } elseif ($pagato >= $importo && $importo > 0) {
                            $stato = 'pagata';
                        } elseif ($pagato > 0 && $pagato < $importo) {
                            $stato = 'parzialmente_pagata'; // <--- ORA QUESTO VIENE GESTITO
                        }

                        // Prendiamo l'ultima data di pagamento disponibile
                        $dataPagamento = $q->whereNotNull('data_pagamento')
                                           ->sortByDesc('data_pagamento')
                                           ->first()
                                           ?->data_pagamento;

                        return [
                            'numero'   => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'  => $importo,
                            // 🔥 DATI AGGIUNTI PER IL FRONTEND
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
                    'rate' => $rate,
                ];
            })
            ->values();
    }

    /**
     * Returns all quotes grouped by Immobile (unit/apartment).
     */
    public function quotePerImmobile(PianoRate $pianoRate): Collection
    {
        return $pianoRate->rate
            ->flatMap->rateQuote
            ->whereNotNull('immobile_id')
            ->groupBy('immobile_id')
            ->map(function ($quotes) {

                $immobile = $quotes->first()->immobile;

                $rate = $quotes
                    ->groupBy('rata_id')
                    ->map(function ($q) {
                        $rata    = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagato  = $q->sum('importo_pagato');

                        // Logica Stato Identica
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
                            // 🔥 DATI AGGIUNTI
                            'importo_pagato' => $pagato,
                            'stato'          => $stato,
                            'data_pagamento' => $dataPagamento ? $dataPagamento->format('Y-m-d') : null,
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
                    'rate' => $rate,
                ];
            })
            ->values();
    }
}