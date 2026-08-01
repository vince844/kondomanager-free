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
use App\Services\Gestionale\DoubleEntryValidator;
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

            // Quota di appoggio per l'eventuale eccedenza: l'ultima quota del
            // pagante toccata dall'incasso (vedi blocco eccedenza più sotto).
            $quotaPerEccedenza = null;
            $ultimaQuotaFaro   = null;

            foreach ($pagamentiOrdinari as $pagamento) {
                if ($budgetCashCents <= 0) break;

                $targetRataCents = MoneyHelper::toCents($pagamento['importo']);
                $importoDaDistribuireCents = min($targetRataCents, $budgetCashCents);

                if ($importoDaDistribuireCents <= 0) continue;

                $quotaFaro = RataQuote::findOrFail($pagamento['rata_id']);
                $ultimaQuotaFaro = $quotaFaro;

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

                        $quotaPerEccedenza = $quota;

                        $importoDaDistribuireCents -= $importoDaVersareQui;
                        $budgetCashCents -= $importoDaVersareQui;
                    }
                }

                if ($importoDaDistribuireCents > 0) {
                    $eccedenzaFinaleCents += $importoDaDistribuireCents;
                    $budgetCashCents -= $importoDaDistribuireCents;
                }
            }

            // Solo la parte di eccedenza effettivamente finanziata dai CONTANTI
            // appartiene a questa scrittura, che in DARE porta i soli contanti.
            // Quando l'incasso combina denaro e credito pregresso (es. debito 100,
            // credito 40, contanti 90 → eccedenza 30), l'eccedenza è finanziata dal
            // credito: contabilizzarla qui sbilanciava il giornale di quell'importo.
            // La parte residua viene restituita al credito nella scrittura 2.
            $eccedenzaCassaCents = min($eccedenzaFinaleCents, $budgetCashCents);
            $eccedenzaDaCreditoCents = $eccedenzaFinaleCents - $eccedenzaCassaCents;

            if ($eccedenzaCassaCents > 0) {
                // L'eccedenza viene registrata come STRAPAGAMENTO sull'ultima quota
                // del pagante toccata dall'incasso: così resta visibile nella
                // situazione debitoria come credito (residuo negativo) ed è
                // spendibile in seguito tramite "Usa credito" (compensazione).
                $quotaAccredito = $quotaPerEccedenza;

                if (!$quotaAccredito && $ultimaQuotaFaro) {
                    $quotaAccredito = RataQuote::where('rata_id', $ultimaQuotaFaro->rata_id)
                        ->where('anagrafica_id', $validated['pagante_id'])
                        ->where('importo', '>', 0)
                        ->orderByDesc('importo')
                        ->first();
                }

                if ($quotaAccredito) {
                    $quotaAccredito->pagamenti()->attach($scritturaIncasso->id, [
                        'importo_pagato' => $eccedenzaCassaCents,
                        'data_pagamento' => $validated['data_pagamento'],
                    ]);

                    $scritturaIncasso->righe()->create([
                        'conto_contabile_id' => $contoCrediti->id,
                        'anagrafica_id'      => $quotaAccredito->anagrafica_id,
                        'rata_id'            => $quotaAccredito->rata_id,
                        'immobile_id'        => $quotaAccredito->immobile_id,
                        'tipo_riga'          => 'avere',
                        'importo'            => $eccedenzaCassaCents,
                        'note'               => 'Anticipo / Eccedenza',
                    ]);

                    $quotaAccredito->ricalcolaStato();
                } else {
                    // Fallback legacy: nessuna quota di appoggio disponibile,
                    // l'eccedenza resta una pura scrittura contabile sugli anticipi.
                    $scritturaIncasso->righe()->create([
                        'conto_contabile_id' => $contoAnticipi->id,
                        'anagrafica_id'      => $validated['pagante_id'],
                        'tipo_riga'          => 'avere',
                        'importo'            => $eccedenzaCassaCents,
                        'note'               => 'Anticipo / Eccedenza',
                    ]);
                }
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

                    // La quota passata dal frontend identifica la RATA di origine del
                    // credito. Su quella rata raccogliamo TUTTE le quote del pagante
                    // che portano credito, in entrambe le forme supportate:
                    // - saldo iniziale / anticipo (importo negativo non consumato)
                    // - strapagamento (importo_pagato > importo)
                    $quotaRef = RataQuote::with('rata.pianoRate.gestione:id,nome')
                        ->lockForUpdate()
                        ->findOrFail($pagamentoCredito['rata_id']);

                    $quoteCredito = RataQuote::where('rata_id', $quotaRef->rata_id)
                        ->where('anagrafica_id', $validated['pagante_id'])
                        ->with('rata.pianoRate.gestione:id,nome')
                        ->lockForUpdate()
                        ->get()
                        ->filter(fn($q) => $q->credito_disponibile > 0)
                        ->values();

                    // Il credito è intestato a un'altra persona? Succede nella ricerca
                    // per immobile, fra comproprietari della stessa unità, ed è
                    // legittimo — ma sposta denaro fra due soggetti diversi, che è
                    // cosa più delicata della compensazione fra gestioni (per la quale
                    // il sistema chiede già una conferma esplicita a schermo).
                    // Si accetta solo se i due condividono davvero quell'unità.
                    $creditoDaAltroSoggetto = false;

                    if ($quoteCredito->isEmpty() && $quotaRef->credito_disponibile > 0) {
                        $condividonoUnita = $quotaRef->immobile_id && DB::table('anagrafica_immobile')
                            ->where('immobile_id', $quotaRef->immobile_id)
                            ->where('anagrafica_id', $validated['pagante_id'])
                            ->exists();

                        if (! $condividonoUnita) {
                            throw new \RuntimeException(
                                'Il credito selezionato è intestato a un altro soggetto che non risulta collegato '
                                . "a quell'unità immobiliare: non può essere usato per pagare questo debito. "
                                . 'Seleziona un credito del pagante, oppure registra separatamente il rimborso fra i due soggetti.'
                            );
                        }

                        $creditoDaAltroSoggetto = true;
                        $quoteCredito = collect([$quotaRef]);
                    }

                    if ($quoteCredito->isEmpty()) {
                        throw new \RuntimeException('Quota credito non trovata per rata_id: ' . $pagamentoCredito['rata_id']);
                    }

                    // Tutte le quote di $quoteCredito condividono la stessa rata,
                    // quindi la stessa gestione: la leggiamo una sola volta per
                    // sapere se questa compensazione attraversa gestioni diverse.
                    $gestioneCredito = $quoteCredito->first()->rata->pianoRate->gestione ?? null;

                    $creditoResiduo = $quoteCredito->sum(fn($q) => $q->credito_disponibile);

                    if ($creditoDaConsumareCents > $creditoResiduo) {
                        throw new \RuntimeException('Credito insufficiente. Disponibile: ' . MoneyHelper::format($creditoResiduo) . ', richiesto: ' . MoneyHelper::format($creditoDaConsumareCents));
                    }

                    // --- LATO DARE (Svuotiamo il Salvadanaio / lo strapagamento) ---
                    $daPrelevareCents = $creditoDaConsumareCents;

                    foreach ($quoteCredito as $quotaCredito) {
                        if ($daPrelevareCents <= 0) break;

                        $prelievoCents = min($daPrelevareCents, $quotaCredito->credito_disponibile);

                        $scritturaStorno->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quotaCredito->anagrafica_id,
                            'rata_id'            => $quotaCredito->rata_id,
                            'immobile_id'        => $quotaCredito->immobile_id,
                            'tipo_riga'          => 'dare',
                            'importo'            => $prelievoCents,
                            // Se il credito viene da un altro soggetto la riga lo dichiara:
                            // in estratto conto deve restare leggibile di chi era quel denaro.
                            'note'               => $creditoDaAltroSoggetto
                                ? 'Utilizzo credito pregresso di ' . ($quotaCredito->anagrafica?->nome ?? "anagrafica #{$quotaCredito->anagrafica_id}")
                                    . ' (comproprietario della stessa unità)'
                                : 'Utilizzo credito pregresso',
                        ]);

                        // 1. Attach alla pivot (con importo negativo per ridurre il credito)
                        $quotaCredito->pagamenti()->attach($scritturaStorno->id, [
                            'importo_pagato' => -$prelievoCents,
                            'data_pagamento' => $validated['data_pagamento'],
                        ]);

                        // 2. Ricalcola (senza update manuale ridondante)
                        $quotaCredito->ricalcolaStato();

                        $daPrelevareCents -= $prelievoCents;
                    }

                    // --- LATO AVERE (Chiudiamo il debito sulla rata) ---
                    $budgetCreditoCents = $creditoDaConsumareCents;

                    foreach ($pagamentiOrdinari as $pagOrd) {
                        if ($budgetCreditoCents <= 0) break;

                        $quotaFaroOrd = RataQuote::find($pagOrd['rata_id']);
                        if (!$quotaFaroOrd) continue;

                        $quoteOrdConResiduo = RataQuote::where('rata_id', $quotaFaroOrd->rata_id)
                            ->where('anagrafica_id', $validated['pagante_id'])
                            ->where('importo', '>', 0)
                            ->with('rata.pianoRate.gestione:id,nome')
                            ->lockForUpdate()
                            ->get()
                            ->filter(fn($q) => ($q->importo - $q->importo_pagato) > 0);

                        foreach ($quoteOrdConResiduo as $quotaOrd) {
                            if ($budgetCreditoCents <= 0) break;

                            $residuoOrd = $quotaOrd->importo - $quotaOrd->importo_pagato;
                            $daApplicare = min($budgetCreditoCents, $residuoOrd);

                            $gestioneRata = $quotaOrd->rata->pianoRate->gestione ?? null;

                            // Compensazione tra gestioni diverse (es. credito ordinaria
                            // usato su rata straordinaria): non impedita, ma tracciata
                            // esplicitamente. L'amministratore l'ha confermata a schermo
                            // (banner "Nuovo incasso"); qui la registriamo comunque, anche
                            // se il flag di conferma frontend non dovesse arrivare, così la
                            // nota in Estratto Conto resta sempre veritiera.
                            $noteCompensazione = ($gestioneCredito && $gestioneRata && $gestioneCredito->id !== $gestioneRata->id)
                                ? "Compensazione cross-gestione confermata dall'amministratore: {$gestioneCredito->nome} → {$gestioneRata->nome} — rata n." . ($quotaOrd->rata->numero_rata ?? '')
                                : 'Compensazione credito su rata n.' . ($quotaOrd->rata->numero_rata ?? '');

                            $scritturaStorno->righe()->create([
                                'conto_contabile_id' => $contoCrediti->id,
                                'anagrafica_id'      => $quotaOrd->anagrafica_id,
                                'rata_id'            => $quotaOrd->rata_id,
                                'immobile_id'        => $quotaOrd->immobile_id,
                                'tipo_riga'          => 'avere',
                                'importo'            => $daApplicare,
                                'note'               => $noteCompensazione,
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

                    // Il credito prelevato in DARE può eccedere il debito residuo:
                    // succede ogni volta che contanti + credito superano il dovuto
                    // (debito 100, credito 40, contanti 90 → 30 in eccesso). Quella
                    // parte non è stata usata e va RESTITUITA al credito, altrimenti
                    // la scrittura resta sbilanciata e il condòmino perde credito che
                    // non ha speso. La restituzione va sulle stesse quote da cui il
                    // credito è stato prelevato: effetto netto = solo quanto servito.
                    if ($budgetCreditoCents > 0) {
                        foreach ($quoteCredito as $quotaCredito) {
                            if ($budgetCreditoCents <= 0) break;

                            $giaPrelevato = abs((int) DB::table('quota_scrittura')
                                ->where('rate_quota_id', $quotaCredito->id)
                                ->where('scrittura_contabile_id', $scritturaStorno->id)
                                ->sum('importo_pagato'));

                            $daRestituire = min($budgetCreditoCents, $giaPrelevato);
                            if ($daRestituire <= 0) continue;

                            $scritturaStorno->righe()->create([
                                'conto_contabile_id' => $contoCrediti->id,
                                'anagrafica_id' => $quotaCredito->anagrafica_id,
                                'rata_id' => $quotaCredito->rata_id,
                                'immobile_id' => $quotaCredito->immobile_id,
                                'tipo_riga' => 'avere',
                                'importo' => $daRestituire,
                                'note' => 'Credito non utilizzato, riaccreditato',
                            ]);

                            $quotaCredito->pagamenti()->attach($scritturaStorno->id, [
                                'importo_pagato' => $daRestituire,
                                'data_pagamento' => $validated['data_pagamento'],
                            ]);

                            $quotaCredito->ricalcolaStato();

                            $budgetCreditoCents -= $daRestituire;
                        }
                    }
                }
            }

            // Quadratura verificata anche qui: fino alla v1.10 il DoubleEntryValidator
            // era chiamato solo dal ciclo passivo (fatture e pagamenti), mentre incassi,
            // storni incassi ed emissioni rate scrivevano nel giornale senza alcun
            // controllo. Il check a monte (riga 38) valida l'INPUT dell'utente, non il
            // ledger prodotto: sono due cose diverse.
            DoubleEntryValidator::validateOrFail($scritturaIncasso->id);

            if (isset($scritturaStorno) && $scritturaStorno->righe()->exists()) {
                DoubleEntryValidator::validateOrFail($scritturaStorno->id);
            }
        });
    }
}