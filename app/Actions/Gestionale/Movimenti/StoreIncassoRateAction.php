<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Models\Condominio;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Anagrafica;
use Illuminate\Support\Facades\DB;

class StoreIncassoRateAction
{
    public function execute(array $validated, Condominio $condominio, $esercizio): void
    {
        $somma = array_reduce(
            $validated['dettaglio_pagamenti'],
            fn ($carry, $item) => $carry + $item['importo'],
            0
        );

        $totaleCalc = round($somma + ($validated['eccedenza'] ?? 0), 2);

        if (abs($validated['importo_totale'] - $totaleCalc) > 0.01) {
            throw new \RuntimeException('Totale non corrispondente.');
        }

        $importoTotaleCents = (int) round($validated['importo_totale'] * 100);

        DB::transaction(function () use ($validated, $condominio, $esercizio, $importoTotaleCents) {

            $cassa = Cassa::with('contoContabile')->findOrFail($validated['cassa_id']);

            $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)
                ->where('ruolo', 'crediti_condomini')
                ->firstOrFail();

            $contoAnticipi = ContoContabile::where('condominio_id', $condominio->id)
                ->where('ruolo', 'anticipi_condomini')
                ->first() ?? $contoCrediti;

            $gestioneId = $validated['gestione_id'] ?? null;

            if (!$gestioneId && !empty($validated['dettaglio_pagamenti'])) {
                $ids = collect($validated['dettaglio_pagamenti'])->pluck('rata_id');
                $quote = RataQuote::whereIn('id', $ids)->with('rata.pianoRate')->get();

                if ($quote->count() > 0) {
                    $gestioneId = $quote->first()->rata->pianoRate->gestione_id;
                }
            }

            if (!$gestioneId) {
                $gestioneId = $esercizio->gestioni()->first()->id;
            }

            $scrittura = ScritturaContabile::create([
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizio->id,
                'gestione_id' => $gestioneId,
                'data_registrazione' => now(),
                'data_competenza' => $validated['data_pagamento'],
                'causale' => $validated['descrizione'] ?: 'Incasso rate',
                'tipo_movimento' => 'incasso_rata',
                'stato' => 'registrata',
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $cassa->contoContabile->id,
                'cassa_id' => $cassa->id,
                'tipo_riga' => 'dare',
                'importo' => $importoTotaleCents,
                'note' => 'Versamento rate ' . Anagrafica::find($validated['pagante_id'])->nome,
            ]);

            foreach ($validated['dettaglio_pagamenti'] as $pagamento) {

                $importoCents = (int) round($pagamento['importo'] * 100);

                $quota = RataQuote::lockForUpdate()->findOrFail($pagamento['rata_id']);

                $quota->pagamenti()->attach($scrittura->id, [
                    'importo_pagato' => $importoCents,
                    'data_pagamento' => $validated['data_pagamento'],
                ]);

                $scrittura->righe()->create([
                    'conto_contabile_id' => $contoCrediti->id,
                    'anagrafica_id' => $quota->anagrafica_id,
                    'rata_id' => $quota->rata_id,
                    'immobile_id' => $quota->immobile_id,
                    'tipo_riga' => 'avere',
                    'importo' => $importoCents,
                    'note' => 'Incasso rata n.' . ($quota->rata->numero_rata ?? ''),
                ]);

                $quota->ricalcolaStato();
            }

            if (!empty($validated['eccedenza']) && $validated['eccedenza'] > 0) {
                $scrittura->righe()->create([
                    'conto_contabile_id' => $contoAnticipi->id,
                    'anagrafica_id' => $validated['pagante_id'],
                    'tipo_riga' => 'avere',
                    'importo' => (int) round($validated['eccedenza'] * 100),
                    'note' => 'Anticipo / Eccedenza',
                ]);
            }
        });
    }
}
