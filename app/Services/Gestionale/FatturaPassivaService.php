<?php

namespace App\Services\Gestionale;

use App\Enums\ContoContabileCategoria;
use App\Enums\ContoContabileTipo;
use App\Events\Gestionale\FatturaRegistrata;
use App\Models\CategoriaDocumento;
use App\Models\Fornitore;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class FatturaPassivaService
{
    public function registraFattura(array $data, int $condominioId, ?UploadedFile $file = null): FatturaPassiva
    {
        return DB::transaction(function () use ($data, $condominioId, $file) {

            $fornitore      = Fornitore::with('referenti')->findOrFail($data['fornitore_id']);
            $isNotaCredito  = ($data['tipo_documento'] === 'nota_credito');
            $moltiplicatore = $isNotaCredito ? -1 : 1;
            
            $isPregresso    = filter_var($data['is_pregresso'] ?? false, FILTER_VALIDATE_BOOLEAN); 

            $imponibileTotale = 0;
            $ivaTotale        = 0;
            $righeProcessate  = [];
            $aliquotaPregressaSalvata = 22; // Default per il DB

            $dynamicContoComuneId  = null;
            $logLegale = $data['dati_extra']['log_legale_sopravvenienza'] ?? null;

            // 1. Calcolo Imponibile e IVA
            if ($isPregresso) {
                $impPregresso = (int) round(($data['imponibile_pregresso'] ?? 0) * 100);
                $aliqPregressa = (float) ($data['aliquota_iva_pregressa'] ?? 22);
                $ivaPregressa = (int) round(($impPregresso * $aliqPregressa) / 100);

                $imponibileTotale = $impPregresso;
                $ivaTotale        = $ivaPregressa;
                $aliquotaPregressaSalvata = $aliqPregressa;
            } else {
                foreach ($data['righe'] as $rigaInput) {
                    $impRiga = (int) round($rigaInput['importo_imponibile'] * 100);
                    $aliq    = (float) $rigaInput['aliquota_iva'];
                    $ivaRiga = (int) round(($impRiga * $aliq) / 100);
                    $isSopravvenienza = filter_var($rigaInput['is_sopravvenienza'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    $imponibileTotale += $impRiga;
                    $ivaTotale        += $ivaRiga;

                    // SMISTAMENTO INTELLIGENTE IMPREVISTI (Millesimale vs Ad Personam)
                    $contoIdRiga = $rigaInput['conto_id'] ?? null;

                    if ($isSopravvenienza) {
                        if (!empty($rigaInput['immobile_id'])) {
                            // SOTTO-CASO: Spesa Privata Imprevista
                            // FIX: NON creiamo nessun Conto nel Piano dei Conti/Preventivo!
                            $contoIdRiga = null; 
                        } else {
                            // SOTTO-CASO: Spesa Comune Imprevista
                            // Creiamo il conto dinamico solo per la quota condominiale
                            if (!$dynamicContoComuneId && $logLegale) {
                                $dynamicContoComuneId = $this->creaContoDinamicoSopravvenienza(
                                    $condominioId, $data['gestione_id'], $fornitore->id, $logLegale
                                );
                            }
                            $contoIdRiga = $dynamicContoComuneId;
                        }
                    } else {
                        // Se l'utente seleziona un capitolo (es. Manutenzione Idraulica) MA assegna l'immobile,
                        // forziamo a null per evitare che sporchi il preventivo comune.
                        if (!empty($rigaInput['immobile_id'])) {
                            $contoIdRiga = null;
                        }
                    }

                    $righeProcessate[] = [
                        'descrizione'        => $rigaInput['descrizione'],
                        'importo_imponibile' => $impRiga * $moltiplicatore,
                        'aliquota_iva'       => $aliq,
                        'importo_iva'        => $ivaRiga * $moltiplicatore,
                        'conto_id'           => $contoIdRiga, // Null se spesa privata
                        'immobile_id'        => $rigaInput['immobile_id'] ?? null,
                        'is_sopravvenienza'  => $isSopravvenienza,
                    ];
                }
            }

            // 2. Calcolo Ritenuta
            $ritenuta     = 0;
            $datiRitenuta = null;
            
            // FIX: Calcoliamo SEMPRE la ritenuta, anche per le note di credito
            // La ritenuta si calcola sempre tranne quando esplicitamente disabilitata
            // (es. storno di fattura con ritenuta già versata all'erario)
            $applicaRitenuta = $fornitore->soggetto_ritenuta && ($data['applica_ritenuta'] ?? true);
            if ($applicaRitenuta) {
                $base = (int) round($imponibileTotale * ($fornitore->perc_imponibile_ritenuta / 100));
                $ritenuta = (int) round($base * ($fornitore->perc_ritenuta / 100));
                $datiRitenuta = [
                    'imponibile_calcolo' => $base,
                    'aliquota'           => $fornitore->perc_ritenuta,
                    'codice_tributo'     => $fornitore->codice_tributo,
                ];
            }

            $totaleDoc = $imponibileTotale + $ivaTotale;
            
            // Il moltiplicatore resta per la testata della fattura a database
            $netto = ($totaleDoc - $ritenuta) * $moltiplicatore;

            $statoApprovazione = $data['stato_approvazione'] ?? 'approvata';
            if (!empty($data['dati_extra']['override_budget'])) {
                $statoApprovazione = 'sforo_motivato';
            }

            // 3. Creazione Fattura
            $fattura = FatturaPassiva::create([
                'condominio_id'      => $condominioId,
                'fornitore_id'       => $fornitore->id,
                'esercizio_id'       => $data['esercizio_id'],
                'conto_corrente_id'  => $data['conto_corrente_id'] ?? null,
                'tipo_documento'     => $data['tipo_documento'],
                'numero_documento'   => $data['numero_documento'],               
                'data_documento'     => $data['data_documento'],
                'data_scadenza'      => $data['data_scadenza'],
                'is_pregresso'       => $isPregresso,
                'data_competenza_originaria' => $data['data_competenza_originaria'] ?? null,
                'saldo_patrimoniale_id'      => $data['saldo_patrimoniale_id'] ?? null,
                'imponibile_pregresso'   => $isPregresso ? $imponibileTotale : 0,
                'aliquota_iva_pregressa' => $isPregresso ? $aliquotaPregressaSalvata : 0,
                'importo_imponibile' => $imponibileTotale * $moltiplicatore,
                'importo_iva'        => $ivaTotale * $moltiplicatore,
                'importo_ritenuta'   => $ritenuta * $moltiplicatore,
                'totale_documento'   => $totaleDoc * $moltiplicatore,
                'netto_a_pagare'     => $netto,
                'stato_pagamento'    => 'aperta',
                'stato_approvazione' => $statoApprovazione,
                'modalita_pagamento' => $data['modalita_pagamento'],
                'iban_fornitore'     => $data['iban_fornitore'] ?? null,
                'dati_extra'         => [
                    'fiscal'          => array_merge(
                        $data['dati_extra']['fiscal'] ?? [],
                        ['ritenuta_details' => $datiRitenuta]
                    ),
                    'competenza'      => $data['dati_extra']['competenza'] ?? null,
                    'override_budget' => $data['dati_extra']['override_budget'] ?? null,
                ],
            ]);

            if (!$isPregresso && !empty($righeProcessate)) {
                $fattura->righe()->createMany($righeProcessate);
            }

            // =====================================================================
            // TRIDENTE: Creazione Copertura se usa Fondo Riserva per sforo
            // NOTA: le coperture sono registri informativi (audit trail).
            // L'effetto contabile reale è esclusivamente nelle righe_scritture.
            // Non esiste doppio movimento: coperture ≠ contabilità.
            // =====================================================================
            $overrideData = $data['dati_extra']['override_budget'] ?? null;
            if ($overrideData && ($overrideData['strategia_rientro'] ?? '') === 'fondo_riserva' && !empty($overrideData['fondo_patrimoniale_id'])) {
                $importoSforo = (int) ($overrideData['importo_sforo'] ?? 0);
                $fattura->coperture()->create([
                    'tipo_copertura'      => 'fondo_riserva',
                    // FIX: Moltiplicatore per azzerare i report in caso di storno
                    'importo'             => $importoSforo * $moltiplicatore, 
                    'stato'               => 'pianificata',
                    'fondo_id'            => $overrideData['fondo_patrimoniale_id'],
                    'nota_amministratore' => "Copertura sforo budget (Art. 1135 c.c.): " . ($overrideData['motivazione'] ?? ''),
                ]);
            }

            // 4. SALVATAGGIO COPERTURE E CALCOLO ECCEDENZA (Pregresso)
            $totaleCopertoPregresso = 0;
            $nuovoContoIdPregresso = null;

            if ($isPregresso && !empty($data['coperture'])) {
                foreach ($data['coperture'] as $index => &$copertura) {
                    $importoCoperturaCents = (int) round($copertura['importo'] * 100);
                    // L'eccedenza interna si calcola sui valori assoluti
                    $totaleCopertoPregresso += $importoCoperturaCents; 

                    $fattura->coperture()->create([
                        'tipo_copertura'      => $copertura['tipo_copertura'],
                        // FIX: Moltiplicatore per i report del DB
                        'importo'             => $importoCoperturaCents * $moltiplicatore, 
                        'stato'               => 'pianificata',
                        'saldo_id'            => $copertura['tipo_copertura'] === 'rata_0' ? $copertura['fonte_id'] : null,
                        'conto_id'            => $copertura['tipo_copertura'] === 'sopravvenienza' ? ($copertura['fonte_id'] ?? null) : null,
                        'fondo_id'            => $copertura['tipo_copertura'] === 'fondo_riserva' ? $copertura['fonte_id'] : null,
                        'nota_amministratore' => $copertura['nota_amministratore'] ?? null,
                    ]);
                }
            }

            // $totaleDoc è già assoluto
            $imponibileFatturaCents = abs($totaleDoc);
            $eccedenzaCents = $imponibileFatturaCents - $totaleCopertoPregresso;

            if ($isPregresso && $eccedenzaCents > 0 && $logLegale) {
                $nuovoContoIdPregresso = $this->creaContoDinamicoSopravvenienza(
                    $condominioId, 
                    $data['gestione_id'], 
                    $fornitore->id, 
                    $logLegale, 
                    $eccedenzaCents
                );

                $fattura->coperture()->create([
                    'tipo_copertura'      => 'sopravvenienza',
                    // FIX: Moltiplicatore per i report del DB
                    'importo'             => $eccedenzaCents * $moltiplicatore, 
                    'stato'               => 'pianificata',
                    'conto_id'            => $nuovoContoIdPregresso,
                    'nota_amministratore' => "Eccedenza fattura pregressa non coperta dai saldi iniziali",
                ]);
            }

            // 5. Salvataggio File
            if ($file) {
                $path = $file->storeAs('documenti/' . $condominioId, $file->hashName(), 'local');
                $categoriaFatture = CategoriaDocumento::where('name', 'Fatture')->first();

                $fattura->documenti()->create([
                    'name'         => $file->getClientOriginalName(),
                    'description'  => 'Fattura passiva n. ' . $data['numero_documento'],
                    'path'         => $path,
                    'mime_type'    => $file->getMimeType(),
                    'file_size'    => $file->getSize(),
                    'created_by'   => Auth::id() ?? 1,
                    'is_published' => false,
                    'is_approved'  => true,
                    'category_id'  => $categoriaFatture ? $categoriaFatture->id : null,
                ]);
            }

            // 6. Contabilità (Partita Doppia)
            $contoDebiti = ContoContabile::where('condominio_id', $condominioId)
                ->where('ruolo', 'debiti_fornitori')
                ->first();

            $contoCreditiCondomini = ContoContabile::where('condominio_id', $condominioId)
                ->where('ruolo', 'crediti_condomini')
                ->first();

            if (!$contoDebiti || !$contoCreditiCondomini) {
                throw new \Exception("Errore Piano dei Conti: Mancano i Mastri 'debiti_fornitori' o 'crediti_condomini'.");
            }

            $scrittura = ScritturaContabile::create([
                'condominio_id'      => $condominioId,
                'esercizio_id'       => $data['esercizio_id'],
                'gestione_id'        => $data['gestione_id'] ?? null,
                'data_registrazione' => now(),
                'data_competenza'    => $fattura->data_documento,
                'numero_protocollo'  => $fattura->numero_protocollo,
                'causale'            => ($isPregresso ? "[PREGRESSO] " : "") . "Ft. {$data['numero_documento']} - {$fornitore->ragione_sociale}",
                'tipo_movimento'     => 'fattura_acquisto',
                'stato'              => 'registrata',
            ]);

            if ($isPregresso) {
                $contoPassateGestioni = ContoContabile::where('condominio_id', $condominioId)
                    ->where('ruolo', 'passate_gestioni')
                    ->firstOrFail();

                $totaleRata0 = 0;

                if (!empty($data['coperture'])) {
                    foreach ($data['coperture'] as $copertura) {
                        $importoCoperturaCents = (int) round($copertura['importo'] * 100);
                        
                        if ($copertura['tipo_copertura'] === 'fondo_riserva') {
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $copertura['fonte_id'],
                                'tipo_riga'          => 'dare',
                                'importo'            => abs($importoCoperturaCents),
                                'note'               => 'Utilizzo fondo riserva per debito pregresso',
                            ]);
                        } else {
                            $totaleRata0 += $importoCoperturaCents;
                        }
                    }
                    
                    if ($totaleRata0 > 0) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoPassateGestioni->id,
                            'tipo_riga'          => 'dare',
                            'importo'            => abs($totaleRata0),
                            'note'               => 'Chiusura debito patrimoniale pregresso',
                        ]);
                    }
                }

                if ($eccedenzaCents > 0) {
                    if ($nuovoContoIdPregresso) {
                        $contoSopravv = Conto::find($nuovoContoIdPregresso);
                        if ($contoSopravv && $contoSopravv->conto_contabile_id) {
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoSopravv->conto_contabile_id,
                                'tipo_riga'          => 'dare',
                                'importo'            => abs($eccedenzaCents),
                                'voce_spesa_id'      => $contoSopravv->id,
                                'note'               => 'Sopravvenienza passiva: eccedenza fattura pregressa',
                            ]);
                        }
                    } else {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoPassateGestioni->id,
                            'tipo_riga'          => 'dare',
                            'importo'            => abs($eccedenzaCents),
                            'note'               => 'Caricamento debito pregresso senza copertura esplicita',
                        ]);
                    }
                }
            } else {
                foreach ($righeProcessate as $riga) {
                    $importoLordoRiga = abs($riga['importo_imponibile'] + $riga['importo_iva']);

                    if (!empty($riga['immobile_id'])) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoCreditiCondomini->id,
                            'tipo_riga'          => 'dare',
                            'importo'            => $importoLordoRiga,
                            'voce_spesa_id'      => null,
                            'immobile_id'        => $riga['immobile_id'],
                            'note'               => 'Anticipazione spesa ad personam (Art. 63)',
                        ]);
                    } elseif (!empty($riga['conto_id'])) {
                        $contoBudget = Conto::find($riga['conto_id']);
                        if ($contoBudget && $contoBudget->conto_contabile_id) {
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoBudget->conto_contabile_id,
                                'tipo_riga'          => 'dare',
                                'importo'            => $importoLordoRiga,
                                'voce_spesa_id'      => $riga['conto_id'],
                                'immobile_id'        => null,
                            ]);
                        } else {
                            throw new \Exception("Integrità compromessa: Manca l'ancoraggio in Partita Doppia per il capitolo di spesa.");
                        }
                    } else {
                        throw new \Exception("Integrità compromessa: Impossibile allocare la riga (nessun immobile e nessun conto associato).");
                    }
                }
            }

            $anagraficaPrincipale = $fornitore->referenti()->first();
            
            // AVERE 1: Debito verso Fornitore
            $scrittura->righe()->create([
                'conto_contabile_id' => $contoDebiti->id,
                'tipo_riga'          => 'avere',
                'importo'            => abs($netto),
                'anagrafica_id'      => $anagraficaPrincipale ? $anagraficaPrincipale->id : null,
            ]);

            // AVERE 2: Debito verso Erario (Ritenute)
            if ($ritenuta > 0) {
                $contoErario = ContoContabile::where('condominio_id', $condominioId)
                    ->where('ruolo', 'debiti_erario_ritenute')
                    ->first();

                if (!$contoErario) throw new \Exception("Errore Piano dei Conti: Manca il Conto Mastro Ritenute.");

                $scrittura->righe()->create([
                    'conto_contabile_id' => $contoErario->id,
                    'tipo_riga'          => 'avere',
                    'importo'            => abs($ritenuta),
                    'anagrafica_id'      => $anagraficaPrincipale ? $anagraficaPrincipale->id : null,
                    'note'               => "Ritenuta d'acconto 4% fattura fornitore"
                ]);
            }
            
            // Registrazione Contabile Sforo Budget
            if ($overrideData && ($overrideData['strategia_rientro'] ?? '') === 'fondo_riserva' && !empty($overrideData['fondo_patrimoniale_id'])) {
                $importoSforo = (int) ($overrideData['importo_sforo'] ?? 0);
                
                if ($importoSforo > 0) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $overrideData['fondo_patrimoniale_id'],
                        'tipo_riga'          => 'dare',
                        'importo'            => abs($importoSforo),
                        'note'               => 'Utilizzo fondo riserva per sforo budget: ' . ($overrideData['motivazione'] ?? ''),
                    ]);

                    $contoSopravvenienza = ContoContabile::where('condominio_id', $condominioId)
                        ->where('ruolo', 'sopravvenienze_passive')
                        ->first();

                    if ($contoSopravvenienza) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoSopravvenienza->id,
                            'tipo_riga'          => 'avere',
                            'importo'            => abs($importoSforo),
                            'note'               => 'Giroconto copertura sforo budget da fondo riserva',
                        ]);
                    }
                }
            }
            
            // =====================================================================
            // IL FILTRO INVERTITORE
            // =====================================================================
            if ($isNotaCredito) {
                DB::table('righe_scritture')
                    ->where('scrittura_id', $scrittura->id)
                    ->update([
                        'tipo_riga' => DB::raw("CASE WHEN tipo_riga = 'dare' THEN 'avere' ELSE 'dare' END")
                    ]);
            }

            // =====================================================================
            // DOUBLE-ENTRY VALIDATOR (Audit Trail & Rollback)
            // =====================================================================
            DoubleEntryValidator::validateOrFail($scrittura->id);

            $fattura->scritture()->attach($scrittura->id, [
                'importo_allocato' => abs($totaleDoc),
                'tipo'             => 'competenza',
            ]);

            event(new FatturaRegistrata($fattura, Auth::id() ?? 1));

            return $fattura;
        });
    }

    /**
     * Metodo Helper: Crea un Capitolo (Conto) al volo per gestire le Sopravvenienze COMUNI.
     */
    private function creaContoDinamicoSopravvenienza(int $condominioId, int $gestioneId, int $fornitoreId, array $logLegale, int $importoCent = 0): int
    {
        $pianoConto = PianoConto::where('gestione_id', $gestioneId)->first();
        if (!$pianoConto) throw new \Exception("Nessun Piano dei Conti trovato per la gestione ID: " . $gestioneId);

        $contoContabileSopravvenienza = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominioId, 'ruolo' => 'sopravvenienze_passive'],
            [
                'codice'      => 'SOP-' . $condominioId,
                'nome'        => 'Sopravvenienze passive',
                'tipo'        => ContoContabileTipo::COSTO->value,
                'categoria'   => ContoContabileCategoria::COSTI->value,
                'descrizione' => 'Costi imprevisti o relativi a esercizi precedenti. Art. 1130-bis c.c.',
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1,
            ]
        );

        $capitoloPadre = Conto::firstOrCreate(
            [
                'piano_conto_id' => $pianoConto->id, 
                'nome' => "Integrazioni Straordinarie (Scudo Legale)", 
                'parent_id' => null
            ],
            [
                'tipo'        => 'spesa',
                'importo'     => 0,
                'descrizione' => 'Capitoli generati automaticamente dal sistema.',
                'is_tecnico'  => true,
            ]
        );

        $origine = isset($logLegale['origine_decisionale'])
            ? ucfirst(strtolower(str_replace('_', ' ', $logLegale['origine_decisionale'])))
            : 'Gestione corrente';

        $nuovoConto = Conto::create([
            'piano_conto_id'       => $pianoConto->id,
            'parent_id'            => $capitoloPadre->id,
            'default_fornitore_id' => $fornitoreId, 
            'nome'                 => $logLegale['nome_voce'] ?? 'Spesa Imprevista',
            'tipo'                 => 'spesa',
            'importo'              => $importoCent, 
            'tipo_ripartizione'    => $logLegale['tipo_ripartizione'] ?? 'millesimale',
            'origine_decisionale'  => $logLegale['origine_decisionale'] ?? 'gestione_corrente',
            'is_tecnico'           => true,
            'note' => implode("\n", array_filter([
                "Origine delibera: " . $origine,
                !empty($logLegale['motivazione_sforo'])
                    ? "Motivazione: " . $logLegale['motivazione_sforo']
                    : null,
            ])),
            'conto_contabile_id'   => $contoContabileSopravvenienza->id,
        ]);

        if (($logLegale['tipo_ripartizione'] ?? 'millesimale') === 'millesimale' && !empty($logLegale['tabella_millesimale_id'])) {
            $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
                'conto_id'     => $nuovoConto->id,
                'tabella_id'   => $logLegale['tabella_millesimale_id'],
                'coefficiente' => 100.00,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $ripartizioni = [
                ['soggetto' => 'proprietario',  'percentuale' => $logLegale['percentuale_proprietario'] ?? 0],
                ['soggetto' => 'inquilino',     'percentuale' => $logLegale['percentuale_inquilino'] ?? 0],
                ['soggetto' => 'usufruttuario', 'percentuale' => $logLegale['percentuale_usufruttuario'] ?? 0]
            ];

            if (array_sum(array_column($ripartizioni, 'percentuale')) != 100) {
                $ripartizioni = [['soggetto' => 'proprietario', 'percentuale' => 100]];
            }

            foreach ($ripartizioni as $rip) {
                if ($rip['percentuale'] > 0) {
                    DB::table('conto_tabella_ripartizioni')->insert([
                        'conto_tabella_millesimale_id' => $contoTabellaId,
                        'soggetto'                     => $rip['soggetto'],
                        'percentuale'                  => $rip['percentuale'],
                        'created_at'                   => now(),
                        'updated_at'                   => now(),
                    ]);
                }
            }
        }

        return $nuovoConto->id;
    }
}