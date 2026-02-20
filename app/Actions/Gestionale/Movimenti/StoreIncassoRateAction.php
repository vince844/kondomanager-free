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

                // Questo è il tesoretto in centesimi da spalmare
                $importoDaDistribuireCents = (int) round($pagamento['importo'] * 100);

                // 1. Troviamo la quota "faro" che ci ha mandato il frontend per risalire alla Rata Padre
                $quotaFaro = RataQuote::findOrFail($pagamento['rata_id']);

                // 2. Recuperiamo TUTTE le quote di QUESTA anagrafica per la STESSA Rata Padre
                $quoteDaSaldare = RataQuote::where('rata_id', $quotaFaro->rata_id)
                    ->where('anagrafica_id', $validated['pagante_id'])
                    ->lockForUpdate()
                    ->orderBy('id') // Assicura che riempia prima 1A, poi 1B, poi 1C
                    ->get();

                // 3. Distribuzione Intelligente a Cascata (Waterfall)
                foreach ($quoteDaSaldare as $quota) {
                    
                    // Se i soldi sono finiti, interrompiamo il ciclo
                    if ($importoDaDistribuireCents <= 0) {
                        break; 
                    }

                    // Calcoliamo quanto manca da pagare su QUESTA specifica quota
                    $debitoResiduoQuota = $quota->importo - $quota->importo_pagato;

                    if ($debitoResiduoQuota > 0) {
                        
                        // Versiamo il minimo tra "quello che ci è rimasto" e "quello che serve alla quota"
                        $importoDaVersareQui = min($importoDaDistribuireCents, $debitoResiduoQuota);

                        // Salviamo la relazione PIVOT per questa quota
                        $quota->pagamenti()->attach($scrittura->id, [
                            'importo_pagato' => $importoDaVersareQui,
                            'data_pagamento' => $validated['data_pagamento'],
                        ]);

                        // Creiamo la riga della scrittura in AVERE per questo specifico immobile
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quota->anagrafica_id,
                            'rata_id'            => $quota->rata_id,
                            'immobile_id'        => $quota->immobile_id,
                            'tipo_riga'          => 'avere',
                            'importo'            => $importoDaVersareQui,
                            'note'               => 'Incasso rata n.' . ($quota->rata->numero_rata ?? ''),
                        ]);

                        // Aggiorniamo lo stato nativo ('pagata', 'parzialmente_pagata', etc.)
                        $quota->ricalcolaStato();

                        // Scaliamo i soldi appena versati dal nostro "tesoretto"
                        $importoDaDistribuireCents -= $importoDaVersareQui;
                    }
                }
                
                // Sicurezza: se per caso l'utente ha mandato più soldi del dovuto per questa rata,
                // l'eccedenza residua la dirottiamo sul conto anticipi.
                if ($importoDaDistribuireCents > 0) {
                    $validated['eccedenza'] = ($validated['eccedenza'] ?? 0) + ($importoDaDistribuireCents / 100);
                }
            }

          /*   foreach ($validated['dettaglio_pagamenti'] as $pagamento) {

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
 */
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
