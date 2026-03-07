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

    public function show(Request $request, Condominio $condominio, Anagrafica $anagrafica)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        $saldoInizialeCents = $anagrafica->saldi()
            ->where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->sum('saldo_iniziale'); 

        $movimenti = $anagrafica->movimenti()
            ->whereHas('scrittura', function($q) use ($condominio) {
                $q->where('condominio_id', $condominio->id);
            })
            ->whereNull('cassa_id')
            ->with(['scrittura.gestione', 'rata', 'immobile']) 
            ->orderBy('created_at', 'asc') 
            ->orderBy('id', 'asc')
            ->get();

        // --- STEP 1: PRE-CARICAMENTO QUOTE (singola query) ---
        $rataIds = $movimenti
            ->filter(fn($r) => $r->rata && $r->tipo_riga === 'dare')
            ->pluck('rata.id')
            ->unique()
            ->values();

        $quoteMap = RataQuote::whereIn('rata_id', $rataIds)
            ->where('anagrafica_id', $anagrafica->id)
            ->get()
            ->keyBy(fn($q) => $q->rata_id . '_' . $q->immobile_id);

        
        // --- STEP 2: PRE-ELABORAZIONE QUOTE PURE (Senza json_decode manuale) ---
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
                    
                    // Laravel trasforma già 'regole_calcolo' in array/oggetto grazie al cast nel modello
                    $regole = $quota->regole_calcolo;

                    if (!empty($regole)) {
                        // Accediamo come array o oggetto a seconda di come Laravel ha castato
                        // Per sicurezza usiamo la sintassi che supporta entrambi o forziamo array
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
        // Per la stessa scrittura, ordina per quota pura decrescente.
        // Così 1C (56€) sarà sempre prima di 1A (33€), indipendentemente dal credito rimasto.
        $movimenti = $movimenti->sortBy(function($riga) {
            return [$riga->scrittura_id, -abs($riga->quotaPura)];
        })->values();

        // --- STEP 4: CREAZIONE TIMELINE & SALDI PROGRESSIVI ---
        $runningBalance = $saldoInizialeCents;
        
        $timeline = $movimenti->map(function ($riga) use (&$runningBalance) {
            
            $importoContabile = $riga->importo; 
            $quotaPura        = $riga->quotaPura;
            $saldoUsato       = $riga->saldoUsato;
            $totaleRichiesto  = $riga->totaleRichiesto;
            $quota            = $riga->quotaRecord;
            
            $waterfallStart = $runningBalance;
            
            if ($riga->tipo_riga === 'dare') {
                $runningBalance += $quotaPura;
                $dare  = $quotaPura; 
                $avere = 0;
            } else {
                $runningBalance -= $importoContabile;
                $dare  = 0; 
                $avere = $importoContabile;
            }
            
            $waterfallEnd = $runningBalance;

            $tipoMovimento = $riga->scrittura->tipo_movimento ?? 'generico';
            $icona = 'file'; 
            if ($tipoMovimento === 'emissione_rata') $icona = 'bill';
            if ($tipoMovimento === 'incasso_rata')   $icona = 'payment';
            if ($tipoMovimento === 'saldo_iniziale') $icona = 'landmark';

            $dettagli  = [];
            $breakdown = null;

            if ($riga->rata) {
    
                $labelBase = "Rata" . ($riga->rata->numero_rata ? " n.{$riga->rata->numero_rata}" : "");
                if ($riga->rata->data_scadenza) {
                    $labelBase .= " (Scad. " . $riga->rata->data_scadenza->format('d/m/Y') . ")";
                }

                if ($riga->tipo_riga === 'dare') {
                    
                    $statoRata = $quota ? $quota->stato : ($waterfallEnd <= 0 ? 'credito' : 'da_pagare');
                    
                    if ($saldoUsato > 0) {
                        $labelBase .= " + Recupero Debito";
                    } elseif ($saldoUsato < 0) {
                        $labelBase .= " (Sconto da Credito)";
                    }

                    $dettagli[] = [
                        'type'   => 'rata',
                        'text'   => $labelBase,
                        'status' => $statoRata 
                    ];

                    if ($saldoUsato != 0) {
                        if ($saldoUsato > 0) {
                            $dettagli[] = [
                                'type'   => 'info',
                                'text'   => "👉 Include recupero debito pregresso: " . MoneyHelper::format($saldoUsato),
                                'status' => null
                            ];
                            $dettagli[] = [
                                'type'   => 'info',
                                'text'   => "💰 Totale richiesto per questa rata: " . MoneyHelper::format($totaleRichiesto),
                                'status' => null
                            ];
                        } else {
                            $dettagli[] = [
                                'type'   => 'info',
                                'text'   => "👉 Scontata da credito pregresso: " . MoneyHelper::format(abs($saldoUsato)),
                                'status' => null
                            ];
                            $dettagli[] = [
                                'type'   => 'info',
                                'text'   => "💰 Valore originale della spesa: " . MoneyHelper::format($quotaPura),
                                'status' => null
                            ];
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

                } else {

                    $dettagli[] = [
                        'type'   => 'rata',
                        'text'   => "A copertura " . $labelBase,
                        'status' => null 
                    ];

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