<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Collection;

class PianoRateQuoteService
{
    /**
     * Returns all quotes grouped by Anagrafica (owner/tenant).
     *
     * Notes:
     * - Uses already-loaded relationships: rate → rateQuote → anagrafica.
     * - Calculates per-rate totals and payment status.
     *
     * @param  PianoRate  $pianoRate
     * @return Collection
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
                        $importo = $q->sum('importo');
                        $pagato  = $q->sum('importo_pagato');

                        return [
                            'numero'   => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'  => $importo,
                            'stato'    => ($pagato >= $importo && $importo > 0)
                                ? 'pagata'
                                : 'non_pagata',
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
     *
     * Notes:
     * - Only includes quotes with immobile_id != null.
     * - Aggregates totals per rata_id.
     *
     * @param  PianoRate  $pianoRate
     * @return Collection
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

                        return [
                            'numero'   => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo'  => $importo,
                            'stato'    => ($pagato >= $importo && $importo > 0)
                                ? 'pagata'
                                : 'non_pagata',
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
