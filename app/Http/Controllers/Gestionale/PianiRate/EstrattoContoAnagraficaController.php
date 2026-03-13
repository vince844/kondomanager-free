<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote; 
use App\Helpers\MoneyHelper;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Illuminate\Http\Request;

class EstrattoContoAnagraficaController extends Controller
{
    use HasEsercizio;

    /**
     * Genera l'Estratto Conto finanziario di una singola Anagrafica.
     * * Questo metodo costruisce un "Ledger" (Libro Mastro) cronologico che incrocia
     * il Saldo Iniziale dell'esercizio con tutte le scritture contabili in Dare/Avere
     * (Emissioni rate, Incassi, Compensazioni e Storni), calcolando il saldo progressivo
     * "Waterfall" (a cascata) al centesimo, basato sul pattern Double-Entry.
     *
     * @param Request $request La richiesta HTTP corrente.
     * @param Condominio $condominio Il Condominio in cui si sta operando.
     * @param Anagrafica $anagrafica L'anagrafica del condòmino di cui calcolare l'estratto conto.
     * @return \Inertia\Response Ritorna la vista Vue dell'estratto conto con le Statistiche e la Timeline elaborata.
     */
    public function show(Request $request, Condominio $condominio, Anagrafica $anagrafica)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

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
                    // 🟢 CASO D: AVERE (Incassi, Compensazioni, e RESTITUZIONE CREDITI DA STORNI)
                    
                    if ($tipoMovimento === 'rettifica') {
                        // FIX CHIRURGICO: Identifica le scritture in AVERE generate da uno Storno
                        // Esempio: La riga che restituisce i 100€ nel Salvadanaio
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

        // COMPILAZIONE STATISTICHE CENTESIMALI FINALI
        $stats = [
            'totale_addebiti'    => MoneyHelper::format($timeline->sum('dare')),
            'totale_versamenti'  => MoneyHelper::format($timeline->sum('avere')),
            'saldo_finale'       => MoneyHelper::format($runningBalance),
            'saldo_raw'          => $runningBalance,
            'saldo_iniziale'     => MoneyHelper::format($saldoInizialeCents),
            'saldo_iniziale_raw' => $saldoInizialeCents
        ];

        return Inertia::render('gestionale/pianiRate/EstrattoContoAnagrafica', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'anagrafica' => $anagrafica,
            'timeline'   => $timeline,
            'stats'      => $stats
        ]);
    }
}