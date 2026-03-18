<?php

namespace App\Services\Gestionale;

use App\Events\Gestionale\FatturaRegistrata;
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

            // 1. Calcolo Imponibile e IVA (Bivio Logico: Pregresso vs Corrente)
            if ($isPregresso) {
                // MODIFICA 1: Lettura dai campi diretti per i debiti storici
                $impPregresso = (int) round(($data['imponibile_pregresso'] ?? 0) * 100);
                $aliqPregressa = (float) ($data['aliquota_iva_pregressa'] ?? 22);
                $ivaPregressa = (int) round(($impPregresso * $aliqPregressa) / 100);

                $imponibileTotale = $impPregresso;
                $ivaTotale        = $ivaPregressa;
                $aliquotaPregressaSalvata = $aliqPregressa;

            } else {
                // Lettura standard dalle righe di dettaglio
                foreach ($data['righe'] as $rigaInput) {
                    $impRiga = (int) round($rigaInput['importo_imponibile'] * 100);
                    $aliq    = (float) $rigaInput['aliquota_iva'];
                    $ivaRiga = (int) round(($impRiga * $aliq) / 100);

                    $imponibileTotale += $impRiga;
                    $ivaTotale        += $ivaRiga;

                    $righeProcessate[] = [
                        'descrizione'        => $rigaInput['descrizione'],
                        'importo_imponibile' => $impRiga * $moltiplicatore,
                        'aliquota_iva'       => $aliq,
                        'importo_iva'        => $ivaRiga * $moltiplicatore,
                        'conto_id'           => $rigaInput['conto_id'],
                        'immobile_id'        => $rigaInput['immobile_id'] ?? null,
                    ];
                }
            }

            // 2. Calcolo Ritenuta
            $ritenuta     = 0;
            $datiRitenuta = null;
            if ($fornitore->soggetto_ritenuta && !$isNotaCredito) {
                $base     = $imponibileTotale * ($fornitore->perc_imponibile_ritenuta / 100);
                $ritenuta = (int) round($base * ($fornitore->perc_ritenuta / 100));
                $datiRitenuta = [
                    'imponibile_calcolo' => $base,
                    'aliquota'           => $fornitore->perc_ritenuta,
                    'codice_tributo'     => $fornitore->codice_tributo,
                ];
            }

            $totaleDoc = $imponibileTotale + $ivaTotale;
            $netto = ($totaleDoc - $ritenuta) * $moltiplicatore;

            // 3. Determinazione stato_approvazione (Override Budget)
            $statoApprovazione = $data['stato_approvazione'] ?? 'approvata';
            if (!empty($data['dati_extra']['override_budget'])) {
                $statoApprovazione = 'sforo_motivato';
            }

            // 4. Creazione Fattura
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
                
                // MODIFICA 2: Salviamo i campi che nutrono il widget
                'imponibile_pregresso'   => $isPregresso ? $imponibileTotale : 0,
                'aliquota_iva_pregressa' => $isPregresso ? $aliquotaPregressaSalvata : 0,

                'importo_imponibile' => $imponibileTotale * $moltiplicatore,
                'importo_iva'        => $ivaTotale * $moltiplicatore,
                'importo_ritenuta'   => $ritenuta,
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

            // Creiamo le righe solo se non è pregresso
            if (!$isPregresso && !empty($righeProcessate)) {
                $fattura->righe()->createMany($righeProcessate);
            }

            // 4.5. SALVATAGGIO COPERTURE E CREAZIONE AUTOMATICA VOCE CON TABELLE (Allineato a Migrazioni)
            if ($isPregresso && !empty($data['coperture'])) {
                foreach ($data['coperture'] as $index => &$copertura) {
                    
                    if ($copertura['tipo_copertura'] === 'sopravvenienza' && empty($copertura['fonte_id'])) {
                        $logLegale = $data['dati_extra']['log_legale_sopravvenienza'] ?? null;
                        
                        if ($logLegale) {
                            $pianoConto = PianoConto::where('gestione_id', $data['gestione_id'])->first();
                            if (!$pianoConto) throw new \Exception("Nessun Piano dei Conti trovato per la gestione ID: " . $data['gestione_id']);

                            // Calcoliamo l'importo in centesimi
                            $importoCent = (int) round($copertura['importo'] * 100);

                            // 1. CAPITOLO PADRE (Restano sempre a 0 nel DB, il frontend calcola i totali)
                            $capitoloPadre = Conto::firstOrCreate(
                                [
                                    'piano_conto_id' => $pianoConto->id,
                                    'nome'           => "Integrazioni Straordinarie (Scudo Legale)",
                                    'parent_id'      => null
                                ],
                                [
                                    'tipo'           => 'spesa',
                                    'importo'        => 0, 
                                    'descrizione'    => 'Capitolo generato automaticamente per urgenze Art. 1135 c.c.'
                                ]
                            );

                            // 2. NUOVA VOCE DI SPESA (I 110 Euro stanno qui, e il Widget li leggerà!)
                            $nuovoConto = Conto::create([
                                'piano_conto_id'       => $pianoConto->id,
                                'parent_id'            => $capitoloPadre->id,
                                'default_fornitore_id' => $data['fornitore_id'], 
                                'nome'                 => $logLegale['nome_voce'],
                                'tipo'                 => 'spesa',
                                'importo'              => $importoCent, 
                                'note'                 => "Aut. " . strtoupper($logLegale['autorizzazione']) . " | " . ($logLegale['note'] ?? ''),
                            ]);

                            // 3. ASSOCIAZIONE TABELLA E RIPARTIZIONI MILLESIMALI
                            if (!empty($logLegale['tabella_millesimale_id'])) {
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

                                // Sicurezza: se non fa 100%, imputiamo tutto al proprietario
                                if (array_sum(array_column($ripartizioni, 'percentuale')) != 100) {
                                    $ripartizioni = [
                                        ['soggetto' => 'proprietario', 'percentuale' => 100],
                                        ['soggetto' => 'inquilino', 'percentuale' => 0],
                                        ['soggetto' => 'usufruttuario', 'percentuale' => 0]
                                    ];
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

                            // Passiamo l'ID generato per il collegamento della fattura e della Partita Doppia
                            $copertura['fonte_id'] = $nuovoConto->id;
                            $data['coperture'][$index]['fonte_id'] = $nuovoConto->id;
                        }
                    }

                    $fattura->coperture()->create([
                        'tipo_copertura'      => $copertura['tipo_copertura'],
                        'importo'             => (int) round($copertura['importo'] * 100),
                        'stato'               => 'pianificata',
                        'saldo_id'            => $copertura['tipo_copertura'] === 'rata_0' ? $copertura['fonte_id'] : null,
                        'conto_id'            => $copertura['tipo_copertura'] === 'sopravvenienza' ? $copertura['fonte_id'] : null,
                        'fondo_id'            => $copertura['tipo_copertura'] === 'fondo_riserva' ? $copertura['fonte_id'] : null,
                        'nota_amministratore' => $copertura['nota_amministratore'] ?? null,
                    ]);
                }
            }

            // 5. Upload Documento (Invariato)
            if ($file) {
                $path = $file->store('fatture/' . $condominioId, 'public');
                $fattura->documenti()->create([
                    'name'         => $file->getClientOriginalName(),
                    'description'  => 'Fattura Passiva n.' . $data['numero_documento'],
                    'path'         => $path,
                    'mime_type'    => $file->getMimeType(),
                    'file_size'    => $file->getSize(),
                    'created_by'   => Auth::id() ?? 1,
                    'is_published' => true,
                    'is_approved'  => true,
                ]);
            }

            // 6. Contabilità (Partita Doppia)
            $contoDebiti = ContoContabile::where('condominio_id', $condominioId)
                ->where('ruolo', 'debiti_fornitori')
                ->first();

            if (!$contoDebiti) {
                throw new \Exception("Errore Piano dei Conti: Manca il Conto Contabile Mastro con ruolo 'debiti_fornitori' per questo condominio.");
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

            // LA MAGIA DELLA PARTITA DOPPIA (Bilanciata!)
            if ($isPregresso) {
                
                if (!empty($data['coperture'])) {
                    // Scenario A: Split Dettagliato (Il Double Lock in azione)
                    foreach ($data['coperture'] as $copertura) {
                        $importoCopertura = (int) round($copertura['importo'] * 100);
                        
                        if ($copertura['tipo_copertura'] === 'sopravvenienza') {
                            $contoBudget = Conto::find($copertura['fonte_id']);
                            
                            // IL PARACADUTE PATRIMONIALE CORRETTO:
                            // Cerchiamo un conto dell'Attivo (es. "Crediti v/Condomini" o "Passate Gestioni")
                            // per bilanciare il debito imprevisto verso il fornitore.
                            $contoPatrimoniale = ContoContabile::where('condominio_id', $condominioId)
                                ->where(function($q) {
                                    $q->where('ruolo', 'passate_gestioni')
                                      ->orWhere('ruolo', 'crediti_condomini')
                                      ->orWhere('categoria', 'crediti');
                                })
                                ->where('tipo', 'attivo')
                                ->first();

                            if (!$contoPatrimoniale) {
                                throw new \Exception("Errore Contabilità: Impossibile trovare un conto patrimoniale di tipo 'Attivo' (es. Crediti v/Condomini o Passate Gestioni) per registrare lo specchio della Sopravvenienza.");
                            }

                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoPatrimoniale->id, // Usa il conto patrimoniale corretto!
                                'tipo_riga'          => $isNotaCredito ? 'avere' : 'dare',
                                'importo'            => $importoCopertura,
                                'voce_spesa_id'      => $contoBudget->id ?? null, // Questo fa il link al Piano dei Conti delle Spese (tabella conti)
                                'note'               => 'Sopravvenienza passiva da fattura pregressa',
                            ]);
                        }

                        elseif ($copertura['tipo_copertura'] === 'fondo_riserva') {
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $copertura['fonte_id'],
                                'tipo_riga'          => $isNotaCredito ? 'avere' : 'dare',
                                'importo'            => $importoCopertura,
                                'note'               => 'Utilizzo fondo per debito pregresso',
                            ]);
                        }
                        elseif ($copertura['tipo_copertura'] === 'rata_0') {
                            // MODIFICA 3: Puntiamo al conto transitorio "Passate Gestioni" che fa da specchio patrimoniale
                            $contoPassateGestioni = ContoContabile::where('condominio_id', $condominioId)
                                ->where('ruolo', 'passate_gestioni')->firstOrFail();

                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoPassateGestioni->id,
                                'tipo_riga'          => $isNotaCredito ? 'avere' : 'dare',
                                'importo'            => $importoCopertura,
                                'note'               => 'Copertura da saldi pregressi (Rata 0)',
                            ]);
                        }
                    }
                } else {
                    // Scenario B: Fallback di Sicurezza 
                    $contoPassateGestioni = ContoContabile::where('condominio_id', $condominioId)
                        ->where('ruolo', 'passate_gestioni') 
                        ->firstOrFail();

                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoPassateGestioni->id,
                        'tipo_riga'          => $isNotaCredito ? 'avere' : 'dare',
                        'importo'            => abs($totaleDoc * $moltiplicatore),
                        'note'               => 'Caricamento debito pregresso senza copertura esplicita',
                    ]);
                }

            } else {
                // Scenario C: DARE — Costi Normali Correnti
                foreach ($righeProcessate as $riga) {
                    if ($riga['conto_id']) {
                        $contoBudget = Conto::find($riga['conto_id']);
                        if ($contoBudget && $contoBudget->conto_contabile_id) {
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoBudget->conto_contabile_id,
                                'tipo_riga'          => $isNotaCredito ? 'avere' : 'dare',
                                'importo'            => abs($riga['importo_imponibile'] + $riga['importo_iva']),
                                'voce_spesa_id'      => $riga['conto_id'],
                                'immobile_id'        => $riga['immobile_id'] ?? null,
                            ]);
                        }
                    }
                }
            }

            $anagraficaPrincipale = $fornitore->referenti()->first();

            // AVERE — Debito v/Fornitore (Sempre uguale, bilancia le righe sopra)
            $scrittura->righe()->create([
                'conto_contabile_id' => $contoDebiti->id,
                'tipo_riga'          => $isNotaCredito ? 'dare' : 'avere',
                'importo'            => abs($totaleDoc * $moltiplicatore),
                'anagrafica_id'      => $anagraficaPrincipale ? $anagraficaPrincipale->id : null,
            ]);

            // Collegamento Pivot
            $fattura->scritture()->attach($scrittura->id, [
                'importo_allocato' => abs($totaleDoc * $moltiplicatore),
                'tipo'             => 'competenza',
            ]);

            // 7. Fire Evento
            event(new FatturaRegistrata($fattura, Auth::id() ?? 1));

            return $fattura;
        });
    }
}