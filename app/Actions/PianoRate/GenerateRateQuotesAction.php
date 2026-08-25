<?php

namespace App\Actions\PianoRate;

use App\Enums\OrigineQuota;
use App\Exceptions\Gestionale\MetodoDistribuzioneSconosciutoException;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        
        $saldiDaDistribuire = $saldi;

        // -----------------------------------------------------------------------------
        // HELPER: Divisione perfetta dei centesimi (Evita la perdita di decimali)
        // -----------------------------------------------------------------------------
        $calcolaFettina = function($totale, $numRate, $rataAttuale) {
            if ($totale == 0) return 0;
            $base = intdiv($totale, $numRate);
            $resto = $totale % $numRate; 
            $segnoResto = $totale < 0 ? -1 : 1;

            // Spalmiamo i "centesimi dispari" (resto) sulle prime rate
            if ($rataAttuale <= abs($resto)) {
                return $base + (1 * $segnoResto);
            }
            return $base;
        };

        DB::beginTransaction();
        try {
            // -----------------------------------------------------------------------------
            // FASE 0: CREAZIONE "RATA ZERO" (SALDO INIZIALE SEPARATO)
            // -----------------------------------------------------------------------------
            if ($pianoRate->metodo_distribuzione === 'rata_zero' && !empty($saldiDaDistribuire)) {
                
                $scadenzaRataZero = $dateRate[0] ?? $now->format('Y-m-d');

                $rataZero = Rata::create([
                    'piano_rate_id'  => $pianoRate->id,
                    'numero_rata'    => 0, 
                    'data_scadenza'  => $scadenzaRataZero,
                    'data_emissione' => $now,
                    'descrizione'    => "Saldo Pregresso - {$pianoRate->nome}",
                    'importo_totale' => 0, 
                    'stato'          => 'bozza',
                ]);

                $importoTotaleRataZero = 0;
                $quotesToInsertZero = [];

                foreach ($saldiDaDistribuire as $aid => $immobiliSaldi) {
                    foreach ($immobiliSaldi as $iid => $datiSaldo) {
                        $importoSaldo = $datiSaldo['importo'];
                        if ($importoSaldo == 0) continue;

                        $statoQuota = $importoSaldo <= 0 ? 'credito' : 'da_pagare';

                        // Costruzione JSON Meta (Fondamentale per i subentri)
                        $snapshot = [
                            'origine' => OrigineQuota::CALCOLO_AUTOMATICO->value,
                            'importi' => [
                                'quota_pura_gestione' => 0, 
                                'saldo_usato'         => $importoSaldo, 
                                'totale_calcolato'    => $importoSaldo
                            ],
                            'parametri' => [
                                'metodo_distribuzione'  => 'rata_zero',
                                'numero_rata'           => 0
                            ],
                            'dettagli_saldo' => $datiSaldo['meta_storico'],
                            'audit' => [
                                'versione_calcolo'  => config('app.version', '1.9.0'), 
                                'generato_il'       => $now->toIso8601String(),
                                'generato_da'       => Auth::check() ? 'user_'.Auth::id() : 'sistema',
                            ]
                        ];

                        $quotesToInsertZero[] = [
                            'rata_id'        => $rataZero->id,
                            'anagrafica_id'  => $aid,
                            'immobile_id'    => $iid > 0 ? $iid : null,
                            'importo'        => $importoSaldo,
                            'importo_pagato' => 0, 
                            'stato'          => $statoQuota,
                            'tipo'           => 'saldo_iniziale',
                            // --- NUOVE COLONNE HARDENING LEGALE ---
                            'origine_tipo'   => 'condominiale', 
                            'stato_legale'   => 'certo',
                            // ---------------------------------------
                            'regole_calcolo' => json_encode($snapshot),
                            'data_scadenza'  => $scadenzaRataZero instanceof Carbon ? $scadenzaRataZero->format('Y-m-d') : $scadenzaRataZero,
                            'created_at'     => $now, 
                            'updated_at'     => $now, 
                        ];

                        $importoTotaleRataZero += $importoSaldo;
                        $quoteCreate++;
                    }
                }

                if (!empty($quotesToInsertZero)) {
                    foreach (array_chunk($quotesToInsertZero, 500) as $chunk) {
                        RataQuote::insert($chunk);
                    }
                }

                $rataZero->update(['importo_totale' => $importoTotaleRataZero]);
                $importoTotaleGenerato += $importoTotaleRataZero;
                $rateCreate++;

                // Svuotiamo l'array: i saldi sono stati consumati e NON andranno nelle rate ordinarie!
                $saldiDaDistribuire = []; 
            }

            // -----------------------------------------------------------------------------
            // PREPARAZIONE: UNIAMO TUTTI I SOGGETTI (Per intercettare i saldi "Orfani")
            // -----------------------------------------------------------------------------
            $tuttiISoggetti = [];
            foreach ($totaliPerImmobile as $aid => $immobili) {
                foreach ($immobili as $iid => $tot) { $tuttiISoggetti[$aid][$iid] = true; }
            }
            foreach ($saldiDaDistribuire as $aid => $immobiliSaldi) {
                foreach ($immobiliSaldi as $iid => $data) { $tuttiISoggetti[$aid][$iid] = true; }
            }

            // -----------------------------------------------------------------------------
            // FASE 1: CREAZIONE RATE ORDINARIE (1..N)
            // -----------------------------------------------------------------------------
            $soggettiQuotaZeroDocumentati = [];

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

                foreach ($tuttiISoggetti as $aid => $immobili) {
                    foreach ($immobili as $iid => $dummy) {
                        
                        $totaleOrdinario = $totaliPerImmobile[$aid][$iid] ?? 0;
                        $datiSaldo = $saldiDaDistribuire[$aid][$iid] ?? null;

                        // 1. Quota Pura (Spalmata precisamente)
                        $quotaOrdinariaRata = $calcolaFettina($totaleOrdinario, $numeroRate, $numeroRata);

                        // 2. Quota Saldo
                        $quotaSaldoRata = 0;
                        $metaStoricoSaldo = null;

                        if ($datiSaldo && $datiSaldo['importo'] != 0) {
                            // Era un `if/elseif` **senza else**: con un valore fuori
                            // vocabolario nessuno dei due rami scattava, la quota restava a
                            // zero su OGNI rata e il pregresso spariva senza eccezione e senza
                            // log — mentre il lucchetto si chiudeva lo stesso e
                            // `gestioni.nota_saldo` registrava un importo «processato» che non
                            // esisteva in nessuna quota.
                            //
                            // Il `match` è esaustivo e lo zero di `rata_zero` è **dichiarato**:
                            // quel pregresso vive nella Rata 0, creata prima di questo ciclo
                            // (vedi `:51`), quindi qui non deve toccare le rate ordinarie. Uno
                            // zero scritto è una decisione; uno zero per caduta è un difetto in
                            // attesa.
                            $quotaSaldoRata = match ($pianoRate->metodo_distribuzione) {
                                'prima_rata' => $numeroRata === 1 ? $datiSaldo['importo'] : 0,
                                'tutte_rate' => $calcolaFettina($datiSaldo['importo'], $numeroRate, $numeroRata),
                                'rata_zero' => 0,
                                default => throw new MetodoDistribuzioneSconosciutoException(
                                    $pianoRate->metodo_distribuzione,
                                    $pianoRate->id
                                ),
                            };

                            $metaStoricoSaldo = $datiSaldo['meta_storico'];
                        }

                        $importoFinale = $quotaOrdinariaRata + $quotaSaldoRata;
                        $eZero = $importoFinale == 0 && $quotaOrdinariaRata == 0 && $quotaSaldoRata == 0;

                        // Un soggetto presente nel calcolo ($totaliPerImmobile ha
                        // la sua chiave) ma a quota zero — tipicamente perché il
                        // netting del già-versato ha azzerato l'intera quota — non
                        // deve sparire senza lasciare traccia: senza questa riga,
                        // guardando le rate generate non c'è modo di distinguere
                        // "l'unità non doveva nulla" da "un errore di
                        // configurazione l'ha esclusa per sbaglio". Una sola riga
                        // documentaria per soggetto (sulla prima rata), non una per
                        // rata: sarebbe rumore senza aggiungere informazione.
                        $chiaveSoggetto = $aid.'|'.$iid;
                        $daDocumentare = $eZero
                            && $numeroRata === 1
                            && array_key_exists($aid, $totaliPerImmobile)
                            && array_key_exists($iid, $totaliPerImmobile[$aid])
                            && !isset($soggettiQuotaZeroDocumentati[$chiaveSoggetto]);

                        if ($eZero && !$daDocumentare) {
                            continue; // Ignora se tutto è a zero e già documentato (o non documentabile)
                        }

                        if ($daDocumentare) {
                            $soggettiQuotaZeroDocumentati[$chiaveSoggetto] = true;
                        }

                        $statoQuota = $importoFinale <= 0 ? 'credito' : 'da_pagare';
                        $tipoRiga = $daDocumentare
                            ? 'coperta_da_versamento'
                            : (($quotaOrdinariaRata == 0 && $quotaSaldoRata != 0) ? 'saldo_iniziale' : 'ordinaria');

                        // 3. Costruzione JSON Meta Tracciabile
                        $snapshot = [
                            'origine' => OrigineQuota::CALCOLO_AUTOMATICO->value,
                            'importi' => [
                                'quota_pura_gestione' => $quotaOrdinariaRata,
                                'saldo_usato'         => $quotaSaldoRata, 
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

                        if ($metaStoricoSaldo && $quotaSaldoRata != 0) {
                            $snapshot['dettagli_saldo'] = $metaStoricoSaldo;
                            if ($pianoRate->metodo_distribuzione === 'tutte_rate') {
                                $snapshot['note_saldo'] = "Quota saldo spalmata ({$numeroRata}/{$numeroRate})";
                            }
                        }

                        // --- INIZIO NUOVA LOGICA EURISTICA ---
                        $origineTipo = 'condominiale';
                        $statoLegale = 'certo';

                        // Se il piano nasce da fatture (straordinario) e la riga ha un immobile associato,
                        // significa che è un addebito ad personam (es. Art. 63 per danni privati).
                        if ($pianoRate->tipo === 'straordinario') {
                            $origineTipo = ($iid > 0) ? 'ad_personam' : 'condominiale';
                            $statoLegale = ($iid > 0) ? 'contestabile' : 'certo';
                        }
                        // --- FINE NUOVA LOGICA EURISTICA ---

                        $quotesToInsert[] = [
                            'rata_id'        => $rata->id,
                            'anagrafica_id'  => $aid,
                            'immobile_id'    => $iid > 0 ? $iid : null,
                            'importo'        => $importoFinale,
                            'importo_pagato' => 0, 
                            'stato'          => $statoQuota,
                            'tipo'           => $tipoRiga,
                            // --- NUOVE COLONNE HARDENING LEGALE ---
                            'origine_tipo'   => $origineTipo,
                            'stato_legale'   => $statoLegale,
                            // ---------------------------------------
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

            DB::commit();

            Log::info("Generazione Rate Completata con nuova logica v1.9", [
                'rate_create' => $rateCreate,
                'quote_create' => $quoteCreate,
                'importo_totale' => $importoTotaleGenerato
            ]);

            return [
                'rate_create' => $rateCreate,
                'quote_create' => $quoteCreate,
                'importo_totale_rate' => $importoTotaleGenerato,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Errore GenerateRateQuotesAction: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }
}