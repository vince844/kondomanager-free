<?php

namespace App\Actions\PianoRate;

use App\Enums\OrigineQuota;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth; 

class GenerateRateQuotesAction
{
    public function execute(
        PianoRate $pianoRate,
        array $totaliPerImmobile,
        array $dateRate,
        array $saldi = []
    ): array {
        $numeroRate = count($dateRate);
        $rateCreate = 0;
        $quoteCreate = 0;
        $importoTotaleGenerato = 0;
        
        $now = now(); 
        
        $saldiInizialiFissi = $saldi;
        $saldiDaConsumare = $saldi;

        // -----------------------------------------------------------------------------
        // FASE 0: CREAZIONE "RATA ZERO" (SALDO INIZIALE SEPARATO)
        // -----------------------------------------------------------------------------
        // Creiamo la Rata 0 SOLO se l'utente ha scelto l'opzione E ci sono saldi reali
        if ($pianoRate->metodo_distribuzione === 'rata_zero' && array_filter($saldiInizialiFissi)) {
            
            // La Rata 0 scade subito (stesso giorno della prima rata ordinaria, o oggi)
            $scadenzaRataZero = $dateRate[0] ?? $now->format('Y-m-d');

            $rataZero = Rata::create([
                'piano_rate_id'  => $pianoRate->id,
                'numero_rata'    => 0, // <-- IL MAGICO NUMERO 0
                'data_scadenza'  => $scadenzaRataZero,
                'data_emissione' => $now,
                'descrizione'    => "Rata Saldo Iniziale - {$pianoRate->nome}",
                'importo_totale' => 0, 
                'stato'          => 'bozza',
            ]);

            $importoTotaleRataZero = 0;
            $quotesToInsertZero = [];

            // Inseriamo SOLO i saldi per ogni anagrafica
            foreach ($saldiInizialiFissi as $aid => $saldo) {
                if ($saldo == 0) continue;

                // Troviamo il primo immobile associato all'anagrafica in questo piano
                // per agganciare la quota. Se non ha immobili qui, non dovrebbe avere saldo.
                $primoImmobileId = isset($totaliPerImmobile[$aid]) ? array_key_first($totaliPerImmobile[$aid]) : null;

                $importoFinale = $saldo;
                $statoQuota = $importoFinale <= 0 ? 'credito' : 'da_pagare';

                $snapshot = [
                    'origine' => OrigineQuota::CALCOLO_AUTOMATICO->value,
                    'importi' => [
                        'quota_pura_gestione' => 0, // Nessuna spesa corrente!
                        'saldo_usato'         => $saldo, 
                        'totale_calcolato'    => $importoFinale
                    ],
                    'parametri' => [
                        'metodo_distribuzione'  => 'rata_zero',
                        'numero_rata'           => 0,
                        'totale_rate_piano'     => $numeroRate
                    ],
                    'audit' => [
                        'versione_calcolo'  => config('app.version', '1.9.0'), 
                        'generato_il'       => $now->toIso8601String(),
                        'generato_da'       => Auth::check() ? 'user_'.Auth::id() : 'sistema',
                    ]
                ];

                $quotesToInsertZero[] = [
                    'rata_id'        => $rataZero->id,
                    'anagrafica_id'  => $aid,
                    'immobile_id'    => $primoImmobileId,
                    'importo'        => $importoFinale,
                    'importo_pagato' => 0, 
                    'stato'          => $statoQuota,
                    'tipo'           => 'saldo_iniziale', // <-- NUOVA COLONNA MIGRATION!
                    // 'esercizio_origine_id' => TODO: In futuro, leggerlo dal DB dei saldi
                    'regole_calcolo' => json_encode($snapshot),
                    'data_scadenza'  => $scadenzaRataZero instanceof Carbon ? $scadenzaRataZero->format('Y-m-d') : $scadenzaRataZero,
                    'created_at'     => $now, 
                    'updated_at'     => $now, 
                ];

                $importoTotaleRataZero += $importoFinale;
                $quoteCreate++;
            }

            if (!empty($quotesToInsertZero)) {
                foreach (array_chunk($quotesToInsertZero, 500) as $chunk) {
                    RataQuote::insert($chunk);
                }
            }

            $rataZero->update(['importo_totale' => $importoTotaleRataZero]);
            $importoTotaleGenerato += $importoTotaleRataZero;
            $rateCreate++;

            // MAGIA: Svuotiamo i saldi, così le rate ordinarie successive non li spalmano due volte!
            $saldiInizialiFissi = []; 
            $saldiDaConsumare = [];
        }


        // -----------------------------------------------------------------------------
        // FASE 1: CREAZIONE RATE ORDINARIE (1, 2, 3...)
        // -----------------------------------------------------------------------------
        foreach ($dateRate as $index => $dataScadenza) {
            $numeroRata = $index + 1;

            $rata = Rata::create([
                'piano_rate_id'  => $pianoRate->id,
                'numero_rata'    => $numeroRata,
                'data_scadenza'  => $dataScadenza,
                'data_emissione' => $now,
                'descrizione'    => "Rata n.{$numeroRata} - {$pianoRate->nome}",
                'importo_totale' => 0, 
                'stato'          => 'bozza',
            ]);

            $importoTotaleRata = 0;
            $quotesToInsert = []; 

            // 2. Calcolo Quote
            foreach ($totaliPerImmobile as $aid => $immobili) {
                
                $saldoDisponibilePerQuestaRata = 0;

                // Questo IF agirà solo se l'utente NON ha scelto 'rata_zero' (visto che sopra azzeriamo l'array)
                if (isset($saldiInizialiFissi[$aid]) && $saldiInizialiFissi[$aid] !== 0) {
                    if ($pianoRate->metodo_distribuzione === 'prima_rata') {
                        if ($numeroRata === 1) {
                            $saldoDisponibilePerQuestaRata = $saldiDaConsumare[$aid] ?? 0;
                        }
                    } elseif ($pianoRate->metodo_distribuzione === 'tutte_rate') {
                        $segnoFettina = $saldiInizialiFissi[$aid] < 0 ? -1 : 1;
                        $absSaldoFisso = abs($saldiInizialiFissi[$aid]);
                        $baseSaldo = intdiv($absSaldoFisso, $numeroRate);
                        $restoSaldo = $absSaldoFisso % $numeroRate;
                        $fettina = $baseSaldo + ($numeroRata <= $restoSaldo ? 1 : 0);
                        $saldoDisponibilePerQuestaRata = $fettina * $segnoFettina;
                    }
                }

                $immobiliOrdinati = collect($immobili)->sortDesc()->toArray();
                $keys = array_keys($immobiliOrdinati);
                $lastIid = end($keys);

                foreach ($immobiliOrdinati as $iid => $totaleImmobile) {
                    if ($totaleImmobile == 0) continue;

                    // 1. Calcolo Quota Pura
                    $segno = $totaleImmobile < 0 ? -1 : 1;
                    $absTot = abs($totaleImmobile);
                    $base = intdiv($absTot, $numeroRate);
                    $resto = $absTot % $numeroRate;
                    $quotaPuraRata = $base + ($numeroRata <= $resto ? 1 : 0);
                    $quotaPuraRata *= $segno;

                    $saldoApplicatoQui = 0;

                    // 2. Waterfall Intelligente Limitato (per Spalmatura Saldi Standard)
                    if ($saldoDisponibilePerQuestaRata !== 0) {
                        if ($saldoDisponibilePerQuestaRata < 0) {
                            $creditoAssoluto = abs($saldoDisponibilePerQuestaRata);
                            $costoAssoluto = abs($quotaPuraRata);

                            if ((string)$iid === (string)$lastIid) {
                                $saldoApplicatoQui = $saldoDisponibilePerQuestaRata;
                            } else {
                                if ($creditoAssoluto >= $costoAssoluto) {
                                    $saldoApplicatoQui = -$costoAssoluto;
                                } else {
                                    $saldoApplicatoQui = -$creditoAssoluto;
                                }
                            }
                        } else {
                            $saldoApplicatoQui = $saldoDisponibilePerQuestaRata;
                        }

                        $saldoDisponibilePerQuestaRata -= $saldoApplicatoQui;

                        if ($pianoRate->metodo_distribuzione === 'prima_rata') {
                            $saldiDaConsumare[$aid] -= $saldoApplicatoQui;
                        }
                    }

                    // 3. Costruzione della riga standard
                    $importoFinale = $quotaPuraRata + $saldoApplicatoQui;

                    if ($importoFinale <= 0) {
                        $statoQuota = 'credito';
                    } elseif ($saldoApplicatoQui < 0) {
                        $statoQuota = 'parzialmente_pagata';
                    } else {
                        $statoQuota = 'da_pagare';
                    }

                    $snapshot = [
                        'origine' => OrigineQuota::CALCOLO_AUTOMATICO->value,
                        'importi' => [
                            'quota_pura_gestione' => $quotaPuraRata,
                            'saldo_usato'         => $saldoApplicatoQui, 
                            'totale_calcolato'    => $importoFinale
                        ],
                        'parametri' => [
                            'metodo_distribuzione'  => $pianoRate->metodo_distribuzione,
                            'numero_rata'           => $numeroRata,
                            'totale_rate_piano'     => $numeroRate
                        ],
                        'audit' => [
                            'versione_calcolo'  => config('app.version', '1.9.0'), 
                            'generato_il'       => $now->toIso8601String(),
                            'generato_da'       => Auth::check() ? 'user_'.Auth::id() : 'sistema',
                        ]
                    ];

                    $quotesToInsert[] = [
                        'rata_id'        => $rata->id,
                        'anagrafica_id'  => $aid,
                        'immobile_id'    => $iid,
                        'importo'        => $importoFinale,
                        'importo_pagato' => 0, 
                        'stato'          => $statoQuota,
                        'tipo'           => 'ordinaria', // <-- NUOVA COLONNA MIGRATION!
                        'regole_calcolo' => json_encode($snapshot),
                        'data_scadenza'  => $dataScadenza instanceof Carbon ? $dataScadenza->format('Y-m-d') : $dataScadenza,
                        'created_at'     => $now, 
                        'updated_at'     => $now, 
                    ];

                    $importoTotaleRata += $importoFinale;
                    $quoteCreate++;
                }
            }

            if (!empty($quotesToInsert)) {
                foreach (array_chunk($quotesToInsert, 500) as $chunk) {
                    RataQuote::insert($chunk);
                }
            }

            $rata->update(['importo_totale' => $importoTotaleRata]);
            $importoTotaleGenerato += $importoTotaleRata;
            $rateCreate++;
        }

        return [
            'rate_create' => $rateCreate,
            'quote_create' => $quoteCreate,
            'importo_totale_rate' => $importoTotaleGenerato,
        ];
    }
}