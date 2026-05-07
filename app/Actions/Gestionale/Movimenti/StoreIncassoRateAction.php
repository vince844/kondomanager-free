<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Models\Condominio;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Anagrafica;
use App\Helpers\MoneyHelper;
use App\Models\Esercizio;
use Illuminate\Support\Facades\DB;

class StoreIncassoRateAction
{
    public function execute(array $validated, Condominio $condominio, Esercizio $esercizio): void
    {
        $pagamentiOrdinari = array_filter(
            $validated['dettaglio_pagamenti'],
            fn($item) => $item['importo'] > 0
        );

        $pagamentiCredito = array_filter(
            $validated['dettaglio_pagamenti'],
            fn($item) => $item['importo'] < 0
        );

        $sommaAlgebricaCents = array_reduce(
            $validated['dettaglio_pagamenti'],
            fn($carry, $item) => $carry + MoneyHelper::toCents($item['importo']),
            0
        );

        $eccedenzaInizialeCents = MoneyHelper::toCents($validated['eccedenza'] ?? 0);
        $importoTotaleCents     = MoneyHelper::toCents($validated['importo_totale']);

        if ($importoTotaleCents !== ($sommaAlgebricaCents + $eccedenzaInizialeCents)) {
            throw new \RuntimeException('Totale non corrispondente.');
        }

        DB::transaction(function () use (
            $validated,
            $condominio,
            $esercizio,
            $importoTotaleCents,
            $eccedenzaInizialeCents,
            $pagamentiOrdinari,
            $pagamentiCredito
        ) {
            $cassa        = Cassa::with('contoContabile')->findOrFail($validated['cassa_id']);
            $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)
                                ->where('ruolo', 'crediti_condomini')
                                ->firstOrFail();
            $contoAnticipi = ContoContabile::where('condominio_id', $condominio->id)
                                ->where('ruolo', 'anticipi_condomini')
                                ->first() ?? $contoCrediti;

            $gestioneId = $validated['gestione_id'] ?? null;

            if (!$gestioneId && !empty($pagamentiOrdinari)) {
                $ids   = collect($pagamentiOrdinari)->pluck('rata_id');
                $quote = RataQuote::whereIn('id', $ids)->with('rata.pianoRate')->get();
                if ($quote->count() > 0) {
                    $gestioneId = $quote->first()->rata->pianoRate->gestione_id;
                }
            }

            if (!$gestioneId) {
                $gestioneId = $esercizio->gestioni()->first()->id;
            }

            // VARIABILI DI CONTROLLO CASSA
            $budgetCashCents      = $importoTotaleCents;
            $eccedenzaFinaleCents = $eccedenzaInizialeCents;

            // ---------------------------------------------------------
            // SCRITTURA 1 — INCASSO REALE (CONTANTI)
            // ---------------------------------------------------------
            $scritturaIncasso = ScritturaContabile::create([
                'condominio_id'      => $condominio->id,
                'esercizio_id'       => $esercizio->id,
                'gestione_id'        => $gestioneId,
                'data_registrazione' => now(),
                'data_competenza'    => $validated['data_pagamento'],
                'causale'            => $validated['descrizione'] ?: 'Incasso rate',
                'tipo_movimento'     => 'incasso_rata',
                'stato'              => 'registrata',
            ]);

            if ($importoTotaleCents > 0) {
                $scritturaIncasso->righe()->create([
                    'conto_contabile_id' => $cassa->contoContabile->id,
                    'cassa_id'           => $cassa->id,
                    'tipo_riga'          => 'dare',
                    'importo'            => $importoTotaleCents,
                    'note'               => 'Versamento rate ' . Anagrafica::find($validated['pagante_id'])->nome,
                ]);
            }

            foreach ($pagamentiOrdinari as $pagamento) {
                if ($budgetCashCents <= 0) break;

                $targetRataCents = MoneyHelper::toCents($pagamento['importo']);
                $importoDaDistribuireCents = min($targetRataCents, $budgetCashCents);

                if ($importoDaDistribuireCents <= 0) continue;

                $quotaFaro = RataQuote::findOrFail($pagamento['rata_id']);

                $quoteDaSaldare = RataQuote::where('rata_id', $quotaFaro->rata_id)
                    ->where('anagrafica_id', $validated['pagante_id'])
                    ->where('importo', '>', 0)
                    ->lockForUpdate()
                    ->get();

                $quoteOrdinate = $quoteDaSaldare->sortByDesc(function ($quota) {
                    $quotaPura = $quota->importo;
                    $regole    = $quota->regole_calcolo;
                    if (!empty($regole)) {
                        $jsonArr  = is_string($regole) ? json_decode($regole, true) : (array) $regole;
                        $quotaPura = $jsonArr['importi']['quota_pura_gestione'] ?? ($jsonArr['audit']['quota_pura'] ?? $quota->importo);
                    }
                    return ($quotaPura * 1000000) - $quota->id;
                })->values();

                foreach ($quoteOrdinate as $quota) {
                    if ($importoDaDistribuireCents <= 0) break;

                    $debitoResiduoQuota = $quota->importo - $quota->importo_pagato;

                    if ($debitoResiduoQuota > 0) {
                        $importoDaVersareQui = min($importoDaDistribuireCents, $debitoResiduoQuota);

                        // 1. Attach alla pivot
                        $quota->pagamenti()->attach($scritturaIncasso->id, [
                            'importo_pagato' => $importoDaVersareQui,
                            'data_pagamento' => $validated['data_pagamento'],
                        ]);

                        // 2. Crea riga contabile
                        $scritturaIncasso->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quota->anagrafica_id,
                            'rata_id'            => $quota->rata_id,
                            'immobile_id'        => $quota->immobile_id,
                            'tipo_riga'          => 'avere',
                            'importo'            => $importoDaVersareQui,
                            'note'               => 'Incasso rata n.' . ($quota->rata->numero_rata ?? ''),
                        ]);

                        // 3. Ricalcola lo stato e salva automaticamente
                        $quota->ricalcolaStato();
                        
                        $importoDaDistribuireCents -= $importoDaVersareQui;
                        $budgetCashCents -= $importoDaVersareQui;
                    }
                }

                if ($importoDaDistribuireCents > 0) {
                    $eccedenzaFinaleCents += $importoDaDistribuireCents;
                    $budgetCashCents -= $importoDaDistribuireCents;
                }
            }

            if ($eccedenzaFinaleCents > 0) {
                $scritturaIncasso->righe()->create([
                    'conto_contabile_id' => $contoAnticipi->id,
                    'anagrafica_id'      => $validated['pagante_id'],
                    'tipo_riga'          => 'avere',
                    'importo'            => $eccedenzaFinaleCents,
                    'note'               => 'Anticipo / Eccedenza',
                ]);
            }

            // ---------------------------------------------------------
            // SCRITTURA 2 — COMPENSAZIONE CREDITO
            // ---------------------------------------------------------
            if (!empty($pagamentiCredito)) {
                $scritturaStorno = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $esercizio->id,
                    'gestione_id'        => $gestioneId,
                    'scrittura_padre_id' => $scritturaIncasso->id, // 🟢 IL LINK DI SANGUE!
                    'data_registrazione' => now(),
                    'data_competenza'    => $validated['data_pagamento'],
                    'causale'            => 'Compensazione credito pregresso - ' . ($validated['descrizione'] ?: 'Incasso rate'),
                    'tipo_movimento'     => 'storno_credito',
                    'stato'              => 'registrata',
                ]);

                foreach ($pagamentiCredito as $pagamentoCredito) {
                    $creditoDaConsumareCents = MoneyHelper::toCents(abs($pagamentoCredito['importo']));

                    $quotaCredito = RataQuote::where('rata_id', function ($q) use ($pagamentoCredito) {
                            $q->select('rata_id')->from('rate_quote')->where('id', $pagamentoCredito['rata_id'])->limit(1);
                        })
                        ->where('anagrafica_id', $validated['pagante_id'])
                        ->where('tipo', 'saldo_iniziale')
                        ->where('importo', '<', 0)
                        ->lockForUpdate()
                        ->first() 
                        ?? 
                        RataQuote::where('id', $pagamentoCredito['rata_id'])
                        ->where('tipo', 'saldo_iniziale')
                        ->where('importo', '<', 0)
                        ->lockForUpdate()
                        ->first();

                    if (!$quotaCredito) {
                        throw new \RuntimeException('Quota credito non trovata per rata_id: ' . $pagamentoCredito['rata_id']);
                    }

                    $creditoResiduo = abs($quotaCredito->importo) - abs($quotaCredito->importo_pagato);

                    if ($creditoDaConsumareCents > $creditoResiduo) {
                        throw new \RuntimeException('Credito insufficiente. Disponibile: ' . MoneyHelper::format($creditoResiduo) . ', richiesto: ' . MoneyHelper::format($creditoDaConsumareCents));
                    }

                    $creditoDaConsumareCents = min($creditoDaConsumareCents, $creditoResiduo);

                    // --- LATO DARE (Svuotiamo il Salvadanaio) ---
                    $scritturaStorno->righe()->create([
                        'conto_contabile_id' => $contoCrediti->id,
                        'anagrafica_id'      => $quotaCredito->anagrafica_id,
                        'rata_id'            => $quotaCredito->rata_id,
                        'immobile_id'        => $quotaCredito->immobile_id,
                        'tipo_riga'          => 'dare',
                        'importo'            => $creditoDaConsumareCents,
                        'note'               => 'Utilizzo credito pregresso',
                    ]);

                    // 1. Attach alla pivot (con importo negativo per ridurre il credito)
                    $quotaCredito->pagamenti()->attach($scritturaStorno->id, [
                        'importo_pagato' => -$creditoDaConsumareCents,
                        'data_pagamento' => $validated['data_pagamento'],
                    ]);

                    // 2. Ricalcola (senza update manuale ridondante)
                    $quotaCredito->ricalcolaStato();

                    // --- LATO AVERE (Chiudiamo il debito sulla rata) ---
                    $budgetCreditoCents = $creditoDaConsumareCents;

                    foreach ($pagamentiOrdinari as $pagOrd) {
                        if ($budgetCreditoCents <= 0) break;

                        $quotaFaroOrd = RataQuote::find($pagOrd['rata_id']);
                        if (!$quotaFaroOrd) continue;

                        $quoteOrdConResiduo = RataQuote::where('rata_id', $quotaFaroOrd->rata_id)
                            ->where('anagrafica_id', $validated['pagante_id'])
                            ->where('importo', '>', 0)
                            ->lockForUpdate()
                            ->get()
                            ->filter(fn($q) => ($q->importo - $q->importo_pagato) > 0);

                        foreach ($quoteOrdConResiduo as $quotaOrd) {
                            if ($budgetCreditoCents <= 0) break;

                            $residuoOrd = $quotaOrd->importo - $quotaOrd->importo_pagato;
                            $daApplicare = min($budgetCreditoCents, $residuoOrd);

                            $scritturaStorno->righe()->create([
                                'conto_contabile_id' => $contoCrediti->id,
                                'anagrafica_id'      => $quotaOrd->anagrafica_id,
                                'rata_id'            => $quotaOrd->rata_id,
                                'immobile_id'        => $quotaOrd->immobile_id,
                                'tipo_riga'          => 'avere',
                                'importo'            => $daApplicare,
                                'note'               => 'Compensazione credito su rata n.' . ($quotaOrd->rata->numero_rata ?? ''),
                            ]);

                            // 1. Attach alla pivot
                            $quotaOrd->pagamenti()->attach($scritturaStorno->id, [
                                'importo_pagato' => $daApplicare,
                                'data_pagamento' => $validated['data_pagamento'],
                            ]);

                            // 2. Ricalcola (senza update manuale ridondante)
                            $quotaOrd->ricalcolaStato();

                            $budgetCreditoCents -= $daApplicare;
                        }
                    }
                }
            }
        });
    }
}