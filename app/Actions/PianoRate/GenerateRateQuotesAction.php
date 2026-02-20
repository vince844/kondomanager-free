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

                // === LA TUA SOLUZIONE CON LE COLLECTION ===
                // Trasformiamo in Collection, ordiniamo in modo decrescente (dal costo più alto al più basso) 
                // e manteniamo le chiavi (immobile_id) intatte.
                $immobiliOrdinati = collect($immobili)->sortDesc()->toArray();
                
                $keys = array_keys($immobiliOrdinati);
                $lastIid = end($keys); // Prendiamo l'ultimo ID in modo sicuro

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

                    // 2. Waterfall Intelligente Limitato
                    if ($saldoDisponibilePerQuestaRata !== 0) {
                        if ($saldoDisponibilePerQuestaRata < 0) {
                            $creditoAssoluto = abs($saldoDisponibilePerQuestaRata);
                            $costoAssoluto = abs($quotaPuraRata);

                            // Controllo rigoroso usando il cast a stringa per evitare falsi positivi del PHP
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

                    // 3. Modifica dell'importo e costruzione Snapshot
                    $importoFinale = $quotaPuraRata + $saldoApplicatoQui;

                    if ($importoFinale <= 0) {
                        $statoQuota = 'credito';
                    } elseif ($saldoApplicatoQui < 0) {
                        // C'era credito ma non sufficiente a coprire tutto
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
                            'generato_il'       => now()->toIso8601String(),
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