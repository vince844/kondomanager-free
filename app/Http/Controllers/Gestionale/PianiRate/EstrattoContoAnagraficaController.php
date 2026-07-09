<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use App\Helpers\MoneyHelper;
use App\Services\Gestionale\CreditoService;
use App\Traits\HasEsercizio;
use App\Services\PDF\PdfService;
use Inertia\Inertia;
use Illuminate\Http\Request;

class EstrattoContoAnagraficaController extends Controller
{
    use HasEsercizio;

    /**
     * Genera l'Estratto Conto finanziario di una singola Anagrafica (vista Inertia).
     * Costruisce un "Ledger" (Libro Mastro) cronologico in Dare/Avere con saldo progressivo
     * "Waterfall" al centesimo, basato sul pattern Double-Entry.
     */
    public function show(Request $request, Condominio $condominio, Anagrafica $anagrafica)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        [$timeline, $stats] = $this->buildLedger($condominio, $esercizio, $anagrafica);

        return Inertia::render('gestionale/pianiRate/EstrattoContoAnagrafica', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'anagrafica' => $anagrafica,
            'timeline'   => $timeline,
            'stats'      => $stats
        ]);
    }

    /**
     * Genera il PDF dell'Estratto Conto di una singola Anagrafica.
     * Aperto in una nuova scheda dal frontend tramite window.open().
     */
    public function print(Request $request, Condominio $condominio, Anagrafica $anagrafica, PdfService $pdfService)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        [$timeline, $stats, $saldoInizialeCents] = $this->buildLedger($condominio, $esercizio, $anagrafica);

        $data = [
            'condominio'         => $condominio,
            'esercizio'          => $esercizio,
            'anagrafica'         => $anagrafica,
            'timeline'           => $timeline,
            'stats'              => $stats,
            'saldoInizialeCents' => $saldoInizialeCents,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.estratto_conto_anagrafica', $data, [
            'orientation' => 'P',
            'margin_top'  => 30,
        ]);

        $nomeFile = 'EC_' . str_replace(' ', '_', $anagrafica->nome) . '_' . $esercizio->nome . '.pdf';
        $mpdf->SetHeader($condominio->nome . '||Estratto Conto – ' . $anagrafica->nome);

        return response($mpdf->Output($nomeFile, 'I'))
            ->header('Content-Type', 'application/pdf');
    }

    // -------------------------------------------------------------------------
    // HELPER PRIVATO — Logica condivisa tra show() e print()
    // -------------------------------------------------------------------------

    /**
     * Costruisce la timeline (ledger) e le statistiche per una anagrafica.
     * Restituisce [$timeline, $stats, $saldoInizialeCents].
     */
    private function buildLedger(Condominio $condominio, Esercizio $esercizio, Anagrafica $anagrafica): array
    {
        // Calcolo Saldo Iniziale (Crediti/Debiti portati dall'anno precedente)
        $saldoInizialeCents = $anagrafica->saldi()
            ->where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->sum('saldo_iniziale'); 

        // Recupero tutti i movimenti dell'anagrafica escludendo i movimenti fittizi di cassa interna
        $movimenti = $anagrafica->movimenti()
            ->whereHas('scrittura', function($q) use ($condominio) {
                $q->where('condominio_id', $condominio->id);
            })
            ->whereNull('cassa_id')
            ->with(['scrittura.gestione', 'rata', 'immobile']) 
            ->orderBy('created_at', 'asc') 
            ->orderBy('id', 'asc')
            ->get();

        // --- STEP 1: PRE-CARICAMENTO QUOTE (singola query per prevenire N+1) ---
        $rataIds = $movimenti
            ->filter(fn($r) => $r->rata && $r->tipo_riga === 'dare')
            ->pluck('rata.id')
            ->unique()
            ->values();

        $quoteMap = RataQuote::whereIn('rata_id', $rataIds)
            ->where('anagrafica_id', $anagrafica->id)
            ->get()
            ->keyBy(fn($q) => $q->rata_id . '_' . $q->immobile_id);

        
        // --- STEP 2: PRE-ELABORAZIONE QUOTE PURE E SALDI USATI ---
        $movimenti->each(function ($riga) use ($quoteMap) {
            $riga->quotaPura       = $riga->importo;
            $riga->saldoUsato      = 0;
            $riga->totaleRichiesto = $riga->importo;
            $riga->quotaRecord     = null;

            if ($riga->rata && $riga->tipo_riga === 'dare') {
                $key   = $riga->rata->id . '_' . $riga->immobile_id;
                $quota = $quoteMap->get($key);

                if ($quota) {
                    $riga->quotaRecord = $quota;
                    
                    $regole = $quota->regole_calcolo;

                    // Decodifica JSON Snapshot per isolare quota reale dal saldo compensato
                    if (!empty($regole)) {
                        $data = (array) $regole;
                        $importi = $data['importi'] ?? [];
                        $riga->saldoUsato      = $importi['saldo_usato'] ?? 0;
                        $riga->quotaPura       = $importi['quota_pura_gestione'] ?? ($riga->importo - $riga->saldoUsato);
                        $riga->totaleRichiesto = $importi['totale_calcolato'] ?? $riga->importo;
                    }
                }
            }
        });

        // --- STEP 3: ORDINAMENTO VISIVO INTELLIGENTE ---
        $movimenti = $movimenti->sortBy(function($riga) {
            // 1. Raggruppamento per scrittura contabile originaria
            // 2. Dare (0) precede Avere (1) per garantire un flusso visivo lineare del Saldo Progressivo
            // 3. A parità di riga, ordina per importo decrescente
            $ordineRiga = $riga->tipo_riga === 'dare' ? 0 : 1; 
            return [$riga->scrittura_id, $ordineRiga, -abs($riga->quotaPura)];
        })->values();

        // --- STEP 4: CREAZIONE TIMELINE & CALCOLO SALDI PROGRESSIVI ---
        $runningBalance = $saldoInizialeCents;
        
        $timeline = $movimenti->map(function ($riga) use (&$runningBalance) {
            
            $importoContabile = $riga->importo; 
            $quotaPura        = $riga->quotaPura;
            $saldoUsato       = $riga->saldoUsato;
            $totaleRichiesto  = $riga->totaleRichiesto;
            $quota            = $riga->quotaRecord;
            
            $waterfallStart = $runningBalance;
            $tipoMovimento = $riga->scrittura->tipo_movimento ?? 'generico';
            
            // CALCOLO DARE E AVERE PROGRESSIVI (Motore Partita Doppia)
            if ($riga->tipo_riga === 'dare') {
                
                // Le rettifiche (storni) e gli utilizzi credito (salvadanaio) in Dare 
                // re-incrementano fisicamente il debito globale.
                if ($tipoMovimento === 'rettifica' || $tipoMovimento === 'storno_credito') {
                    $importoDaSommare = $importoContabile;
                } else {
                    // Emissione standard (ignora i crediti figurativi)
                    $importoDaSommare = $quotaPura;
                }

                $runningBalance += $importoDaSommare;
                $dare  = $importoDaSommare; 
                $avere = 0;

            } else {
                // Gli incassi in contanti, compensazioni e i rimborsi abbattono il debito.
                $runningBalance -= $importoContabile;
                $dare  = 0; 
                $avere = $importoContabile;
            }
            
            $waterfallEnd = $runningBalance;

            // ASSEGNAZIONE ICONE VISIVE
            $icona = 'file'; 
            if ($tipoMovimento === 'emissione_rata') $icona = 'bill';
            if ($tipoMovimento === 'incasso_rata')   $icona = 'payment';
            if ($tipoMovimento === 'saldo_iniziale') $icona = 'landmark';
            if ($tipoMovimento === 'rettifica' || $tipoMovimento === 'storno_credito') $icona = 'rotate-ccw'; 
            
            $dettagli  = [];
            $breakdown = null;

            if ($riga->rata) {
    
                $labelBase = "Rata" . ($riga->rata->numero_rata ? " n.{$riga->rata->numero_rata}" : "");
                if ($riga->rata->data_scadenza) {
                    $labelBase .= " (Scad. " . $riga->rata->data_scadenza->format('d/m/Y') . ")";
                }

                // GESTIONE DESCRIZIONI E BREAKDOWN DELLE SINGOLE VOCI
                if ($riga->tipo_riga === 'dare') {
                    
                    // CASO A: È UNO STORNO O ANNULLAMENTO IN DARE (Ripristino Debito)
                    if ($tipoMovimento === 'rettifica') {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "Storno incasso su " . $labelBase,
                            'status' => 'stornata' // Badge grigio visivo
                        ];
                        $breakdown = [
                            'type'             => 'storno',
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($importoContabile), 
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => $riga->immobile ? $riga->immobile->interno : 'Generico'
                        ];
                    }
                    // CASO B: PRELIEVO DAL SALVADANAIO
                    elseif ($tipoMovimento === 'storno_credito') {
                        $dettagli[] = [
                            'type'   => 'info',
                            'text'   => "Prelievo da salvadanaio per compensazione",
                            'status' => null
                        ];
                        $breakdown = [
                            'type'             => 'storno', 
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($importoContabile), 
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => 'Generico'
                        ];
                    }
                    // CASO C: EMISSIONE STANDARD
                    else {
                        $statoRata = $quota ? $quota->stato : ($waterfallEnd <= 0 ? 'credito' : 'da_pagare');
                        
                        if ($saldoUsato > 0) {
                            $labelBase .= " + Recupero debito";
                        } elseif ($saldoUsato < 0) {
                            $labelBase .= " (Sconto da credito)";
                        }

                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => $labelBase,
                            'status' => $statoRata 
                        ];

                        if ($saldoUsato != 0) {
                            if ($saldoUsato > 0) {
                                $dettagli[] = ['type' => 'info', 'text' => "Include recupero debito pregresso: " . MoneyHelper::format($saldoUsato), 'status' => null];
                                $dettagli[] = ['type' => 'info', 'text' => "Totale richiesto per questa rata: " . MoneyHelper::format($totaleRichiesto), 'status' => null];
                            } else {
                                $dettagli[] = ['type' => 'info', 'text' => "Scontata da credito pregresso: " . MoneyHelper::format(abs($saldoUsato)), 'status' => null];
                                $dettagli[] = ['type' => 'info', 'text' => "Valore originale della spesa: " . MoneyHelper::format($quotaPura), 'status' => null];
                            }
                        }

                        $breakdown = [
                            'type'             => 'emissione',
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($quotaPura),
                            'totale_richiesto' => MoneyHelper::fromCents($totaleRichiesto),
                            'saldo_usato'      => $saldoUsato != 0 ? MoneyHelper::fromCents($saldoUsato) : null,
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => $riga->immobile ? $riga->immobile->interno : 'Generico'
                        ];
                    }

                } else {
                    // CASO D: AVERE (Incassi, Compensazioni, e RESTITUZIONE CREDITI DA STORNI)
                    
                    if ($tipoMovimento === 'rettifica') {
                        // FIX CHIRURGICO: Identifica le scritture in AVERE generate da uno Storno
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "Ripristino credito nel salvadanaio",
                            'status' => 'stornata' 
                        ];
                    } elseif ($tipoMovimento === 'storno_credito') {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "Pagamento tramite credito su " . $labelBase,
                            'status' => null 
                        ];
                    } else {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "A copertura " . $labelBase,
                            'status' => null 
                        ];
                    }

                    $breakdown = [
                        'type'     => 'incasso',
                        'start'    => MoneyHelper::fromCents($waterfallStart),
                        'cost'     => MoneyHelper::fromCents($importoContabile),
                        'end'      => MoneyHelper::fromCents($waterfallEnd),
                        'immobile' => $riga->immobile ? $riga->immobile->interno : 'Generico'
                    ];
                }
            }

            if ($riga->immobile) {
                $dettagli[] = [
                    'type'   => 'immobile',
                    'text'   => $riga->immobile->nome . ($riga->immobile->interno ? " (Int. {$riga->immobile->interno})" : ""),
                    'status' => null
                ];
            }

            return [
                'id'          => $riga->id,
                'data'        => $riga->scrittura->data_registrazione ? $riga->scrittura->data_registrazione->format('d/m/Y') : '-',
                'protocollo'  => $riga->scrittura->numero_protocollo,
                'descrizione' => $riga->scrittura->causale ?: 'Movimento Contabile',
                'gestione'    => $riga->scrittura->gestione ? $riga->scrittura->gestione->nome : null,
                'dettagli'    => $dettagli, 
                'note'        => $riga->note, 
                'tipo_icona'  => $icona, 
                'dare'        => $dare, 
                'avere'       => $avere,
                'saldo'       => $waterfallEnd, 
                'breakdown'   => $breakdown 
            ];
        });

        // Credito disponibile (saldo iniziale a credito + strapagamenti), non
        // riusa $quoteMap perché quella è scoped alle rate presenti in questo
        // esercizio/timeline: qui serve la fotografia completa dell'anagrafica.
        $credito = app(CreditoService::class)->perAnagrafica($condominio->id, $anagrafica->id);

        // COMPILAZIONE STATISTICHE CENTESIMALI FINALI
        $stats = [
            'totale_addebiti'         => MoneyHelper::format($timeline->sum('dare')),
            'totale_versamenti'       => MoneyHelper::format($timeline->sum('avere')),
            'saldo_finale'            => MoneyHelper::format($runningBalance),
            'saldo_raw'               => $runningBalance,
            'saldo_iniziale'          => MoneyHelper::format($saldoInizialeCents),
            'saldo_iniziale_raw'      => $saldoInizialeCents,
            'credito_disponibile'     => $credito['totale_formatted'],
            'credito_disponibile_raw' => $credito['totale_cents'],
            'credito_per_gestione'    => $credito['per_gestione'],
        ];

        return [$timeline, $stats, $saldoInizialeCents];
    }
}