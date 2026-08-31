<?php

namespace App\Services\Gestionale;

use App\DataTransferObjects\RitenutaCalcolo;
use App\Enums\ContoContabileCategoria;
use App\Enums\ContoContabileTipo;
use App\Enums\StatoPagamentoFattura;
use App\Events\Gestionale\FatturaRegistrata;
use App\Exceptions\Pagamenti\FatturaModificaVietataException;
use App\Models\CategoriaDocumento;
use App\Models\Evento;
use App\Models\Fornitore;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FatturaPassivaService
{
    public function registraFattura(array $data, int $condominioId, ?UploadedFile $file = null): FatturaPassiva
    {
        return DB::transaction(function () use ($data, $condominioId, $file) {

            $fornitore = Fornitore::with('referenti')->findOrFail($data['fornitore_id']);
            $isNotaCredito = ($data['tipo_documento'] === 'nota_credito');
            $moltiplicatore = $isNotaCredito ? -1 : 1;

            $isPregresso = filter_var($data['is_pregresso'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $imponibileTotale = 0;
            $ivaTotale = 0;
            $righeProcessate = [];
            $righeRitenuta = [];
            $aliquotaPregressaSalvata = 22; // Default per il DB

            $dynamicContoComuneId = null;
            $logLegale = $data['dati_extra']['log_legale_sopravvenienza'] ?? null;

            // 1. Calcolo Imponibile e IVA
            if ($isPregresso) {
                $impPregresso = (int) round(($data['imponibile_pregresso'] ?? 0) * 100);
                $aliqPregressa = (float) ($data['aliquota_iva_pregressa'] ?? 22);
                $ivaPregressa = (int) round(($impPregresso * $aliqPregressa) / 100);

                $imponibileTotale = $impPregresso;
                $ivaTotale = $ivaPregressa;
                $aliquotaPregressaSalvata = $aliqPregressa;
            } else {
                foreach ($data['righe'] as $rigaInput) {
                    $impRiga = (int) round($rigaInput['importo_imponibile'] * 100);
                    $aliq = (float) $rigaInput['aliquota_iva'];
                    $ivaRiga = (int) round(($impRiga * $aliq) / 100);
                    $isSopravvenienza = filter_var($rigaInput['is_sopravvenienza'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    $imponibileTotale += $impRiga;
                    $ivaTotale += $ivaRiga;

                    // SMISTAMENTO INTELLIGENTE IMPREVISTI (Millesimale vs Ad Personam)
                    $contoIdRiga = $rigaInput['conto_id'] ?? null;

                    if ($isSopravvenienza) {
                        if (! empty($rigaInput['immobile_id'])) {
                            // SOTTO-CASO: Spesa Privata Imprevista
                            // FIX: NON creiamo nessun Conto nel Piano dei Conti/Preventivo!
                            $contoIdRiga = null;
                        } else {
                            // SOTTO-CASO: Spesa Comune Imprevista
                            // Creiamo il conto dinamico solo per la quota condominiale
                            if (! $dynamicContoComuneId && $logLegale) {
                                $dynamicContoComuneId = $this->creaContoDinamicoSopravvenienza(
                                    $condominioId, $data['gestione_id'], $fornitore->id, $logLegale
                                );
                            }
                            $contoIdRiga = $dynamicContoComuneId;
                        }
                    } else {
                        // Se l'utente seleziona un capitolo (es. Manutenzione Idraulica) MA assegna l'immobile,
                        // forziamo a null per evitare che sporchi il preventivo comune.
                        if (! empty($rigaInput['immobile_id'])) {
                            $contoIdRiga = null;
                        }
                    }

                    $righeProcessate[] = [
                        'descrizione' => $rigaInput['descrizione'],
                        'importo_imponibile' => $impRiga * $moltiplicatore,
                        'aliquota_iva' => $aliq,
                        'importo_iva' => $ivaRiga * $moltiplicatore,
                        'conto_id' => $contoIdRiga, // Null se spesa privata
                        'immobile_id' => $rigaInput['immobile_id'] ?? null,
                        'is_sopravvenienza' => $isSopravvenienza,
                        'concorre_base_ritenuta' => filter_var($rigaInput['concorre_base_ritenuta'] ?? true, FILTER_VALIDATE_BOOLEAN),
                        'natura_riga_ritenuta' => $rigaInput['natura_riga_ritenuta'] ?? null,
                    ];

                    $righeRitenuta[] = [
                        'importo_imponibile' => $impRiga,
                        'concorre_base_ritenuta' => filter_var($rigaInput['concorre_base_ritenuta'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    ];
                }
            }

            // 2. Calcolo Ritenuta
            // Nota di credito: default a NON applicata (il frontend non invia la
            // chiave). Lo storno la propaga esplicitamente dall'originale
            // (StornoFatturaController) — design §8 punti 2 e 3.
            $richiestaApplicazioneRitenuta = $data['applica_ritenuta'] ?? ! $isNotaCredito;

            // FIX (revisione avversariale): lo storno passa 'ritenuta_override'
            // per FISSARE l'importo dell'originale, invece di farlo ricalcolare
            // da RitenutaService sullo stato ATTUALE del fornitore. Se
            // l'anagrafica cambia fra registrazione e storno (es. il fornitore
            // diventa forfetario), ricalcolare produce un importo diverso da
            // quello davvero registrato — riapre esattamente il residuo
            // fantasma su 2201/2202 che il design §8 punto 2 doveva chiudere.
            if (! empty($data['ritenuta_override'])) {
                $override = $data['ritenuta_override'];
                $ritenutaCalcolo = RitenutaCalcolo::override(
                    (int) ($override['importo_cents'] ?? 0),
                    isset($override['aliquota']) ? (float) $override['aliquota'] : null,
                    $override['codice_tributo'] ?? null,
                    isset($override['imponibile_calcolo']) ? (int) $override['imponibile_calcolo'] : null,
                );
            } else {
                $ritenutaCalcolo = app(RitenutaService::class)->calcola(
                    $fornitore,
                    $righeRitenuta,
                    $imponibileTotale,
                    $richiestaApplicazioneRitenuta,
                    Carbon::parse($data['data_documento']),
                );
            }

            $ritenuta = $ritenutaCalcolo->importo;
            $datiRitenuta = $ritenutaCalcolo->toLegacyDatiExtra();

            $totaleDoc = $imponibileTotale + $ivaTotale;

            // Il moltiplicatore resta per la testata della fattura a database
            $netto = ($totaleDoc - $ritenuta) * $moltiplicatore;

            $statoApprovazione = $data['stato_approvazione'] ?? 'approvata';
            if (! empty($data['dati_extra']['override_budget'])) {
                $statoApprovazione = 'sforo_motivato';
            }

            // 3. Creazione Fattura
            $fattura = FatturaPassiva::create([
                'condominio_id' => $condominioId,
                'fornitore_id' => $fornitore->id,
                'esercizio_id' => $data['esercizio_id'],
                'conto_corrente_id' => $data['conto_corrente_id'] ?? null,
                'tipo_documento' => $data['tipo_documento'],
                'numero_documento' => $data['numero_documento'],
                'data_documento' => $data['data_documento'],
                'data_scadenza' => $data['data_scadenza'],
                'is_pregresso' => $isPregresso,
                'data_competenza_originaria' => $data['data_competenza_originaria'] ?? null,
                'saldo_patrimoniale_id' => $data['saldo_patrimoniale_id'] ?? null,
                'imponibile_pregresso' => $isPregresso ? $imponibileTotale : 0,
                'aliquota_iva_pregressa' => $isPregresso ? $aliquotaPregressaSalvata : 0,
                'importo_imponibile' => $imponibileTotale * $moltiplicatore,
                'importo_iva' => $ivaTotale * $moltiplicatore,
                'importo_ritenuta' => $ritenuta * $moltiplicatore,
                'totale_documento' => $totaleDoc * $moltiplicatore,
                'netto_a_pagare' => $netto,
                'stato_pagamento' => StatoPagamentoFattura::APERTA,
                'stato_approvazione' => $statoApprovazione,
                'modalita_pagamento' => $data['modalita_pagamento'],
                'iban_fornitore' => $data['iban_fornitore'] ?? null,
                'dati_extra' => [
                    'fiscal' => array_merge(
                        $data['dati_extra']['fiscal'] ?? [],
                        [
                            'ritenuta_details' => $datiRitenuta,
                            'motivo_esclusione_ritenuta' => $this->motivoEsclusioneRitenuta($ritenutaCalcolo, $data),
                        ]
                    ),
                    'competenza' => $data['dati_extra']['competenza'] ?? null,
                    'override_budget' => $data['dati_extra']['override_budget'] ?? null,
                ],
            ]);

            if (! $isPregresso && ! empty($righeProcessate)) {
                $fattura->righe()->createMany($righeProcessate);
            }

            // =====================================================================
            // TRIDENTE: Creazione Copertura se usa Fondo Riserva per sforo
            // NOTA: le coperture sono registri informativi (audit trail).
            // L'effetto contabile reale è esclusivamente nelle righe_scritture.
            // Non esiste doppio movimento: coperture ≠ contabilità.
            //
            // NON C'È UN RAMO per strategia_rientro === 'conguaglio_fine_anno': è il
            // default implicito, non crea nessuna copertura né piano rate qui — lo
            // sforo resta "in attesa", da chiedere alla chiusura esercizio.
            // TODO(chiusura esercizio, non ancora costruita): quando quella
            // funzionalità nascerà, dovrà leggere il già-versato (contributi_versati)
            // esattamente come fa CalcoloQuoteService::guardiaSovraFinanziamentoGiaVersato()
            // per la rata integrativa — altrimenti un conguaglio di fine anno su una
            // voce con già-versato attivo chiederebbe di nuovo l'intero importo lordo,
            // stesso identico bug del "caso del forum" (beta.27), solo spostato a fine
            // esercizio invece che sulla rata integrativa.
            // =====================================================================
            $overrideData = $data['dati_extra']['override_budget'] ?? null;
            if ($overrideData && ($overrideData['strategia_rientro'] ?? '') === 'fondo_riserva' && ! empty($overrideData['fondo_patrimoniale_id'])) {
                $importoSforo = (int) ($overrideData['importo_sforo'] ?? 0);
                $fattura->coperture()->create([
                    'tipo_copertura' => 'fondo_riserva',
                    // FIX: Moltiplicatore per azzerare i report in caso di storno
                    'importo' => $importoSforo * $moltiplicatore,
                    'stato' => 'pianificata',
                    'fondo_id' => $overrideData['fondo_patrimoniale_id'],
                    'nota_amministratore' => 'Copertura sforo budget (Art. 1135 c.c.): '.($overrideData['motivazione'] ?? ''),
                ]);
            } elseif ($overrideData && ($overrideData['strategia_rientro'] ?? '') === 'rata_integrativa') {
                // Per ogni voce coinvolta in questa fattura (non pregressa) che ha
                // già-versato registrato, portiamo `conto->importo` al costo reale
                // adesso — lo stesso passo che CalcoloQuoteService::
                // guardiaSovraFinanziamentoGiaVersato() richiederebbe comunque
                // prima di poter generare un piano rate su quella voce (beta.27,
                // "il caso del forum"). Farlo qui, atomicamente con la
                // registrazione della fattura, elimina il passo manuale separato:
                // scegliere "rata integrativa" per uno sforo reale implica già che
                // questo sia il vero costo dell'opera. Nessun effetto per le voci
                // senza già-versato: la loro `importo` non viene mai toccata qui,
                // il fabbisogno tollerante di BudgetCoverageService resta invariato.
                $contiCoinvolti = collect($righeProcessate)->pluck('conto_id')->filter()->unique();
                // Ogni bump viene registrato qui con l'importo PRECEDENTE — non
                // per ricalcolarlo, ma per poterlo ripristinare esattamente allo
                // storno (vedi StornoFatturaController "FIX 3"). Ricalcolare a
                // fresco dalle righe_fattura residue allo storno sembrava
                // equivalente ma non lo è: dopo uno storno completo la somma
                // netta tornerebbe a 0, cancellando il budget ORIGINALE
                // deliberato (che può essere più alto del semplice speso reale,
                // es. €5.000 deliberati anche se questa era l'unica fattura).
                // Il rollback esatto evita questo, senza bisogno di "indovinare".
                $bumpRegistrati = [];

                foreach ($contiCoinvolti as $contoId) {
                    $haGiaVersato = ContributoVersato::where('target_type', Conto::class)
                        ->where('target_id', $contoId)
                        ->exists();
                    if (! $haGiaVersato) {
                        continue;
                    }

                    // REVISIONE AVVERSARIALE: senza lockForUpdate() qui, due
                    // fatture registrate quasi in contemporanea sullo stesso
                    // conto (due tab, due admin) leggono entrambe la SOMMA
                    // PRIMA che l'altra transazione committi il proprio insert
                    // (lost update classico) — il conto->importo finale riflette
                    // solo l'ULTIMA scrittura vinta, non la somma reale di
                    // entrambe le fatture. Il resto della codebase blocca questa
                    // stessa classe di problema con lockForUpdate() ovunque tocca
                    // un saldo/budget dentro una transazione (BudgetMovementService,
                    // PagamentoFornitoreService, StoreIncassoRateAction) — qui
                    // replichiamo lo stesso pattern: il lock sulla riga `conti`
                    // serializza le transazioni concorrenti sullo stesso conto,
                    // così la seconda a passare rilegge la SOMMA con già dentro
                    // l'insert della prima (ormai committata).
                    $contoDaAggiornare = Conto::lockForUpdate()->find($contoId);
                    if (! $contoDaAggiornare) {
                        continue;
                    }

                    $importoPrecedente = (int) $contoDaAggiornare->importo;

                    $spesoRealeTotale = (int) DB::table('righe_fattura')
                        ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                        ->where('righe_fattura.conto_id', $contoId)
                        ->where('fatture_passive.is_pregresso', false)
                        ->selectRaw('COALESCE(SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva), 0) as tot')
                        ->value('tot');

                    if ($spesoRealeTotale > abs($importoPrecedente)) {
                        $contoDaAggiornare->importo = $spesoRealeTotale;
                        $contoDaAggiornare->save();
                        $bumpRegistrati[] = [
                            'conto_id' => $contoId,
                            'importo_precedente_cents' => $importoPrecedente,
                        ];
                    }
                }

                if (! empty($bumpRegistrati)) {
                    $datiExtraConBump = $fattura->dati_extra;
                    $datiExtraConBump['rata_integrativa_bump'] = $bumpRegistrati;
                    $fattura->update(['dati_extra' => $datiExtraConBump]);
                }
            }

            // 4. SALVATAGGIO COPERTURE E CALCOLO ECCEDENZA (Pregresso)
            $totaleCopertoPregresso = 0;
            $nuovoContoIdPregresso = null;

            if ($isPregresso && ! empty($data['coperture'])) {
                foreach ($data['coperture'] as $index => &$copertura) {
                    $importoCoperturaCents = (int) round($copertura['importo'] * 100);
                    // L'eccedenza interna si calcola sui valori assoluti
                    $totaleCopertoPregresso += $importoCoperturaCents;

                    $fattura->coperture()->create([
                        'tipo_copertura' => $copertura['tipo_copertura'],
                        // FIX: Moltiplicatore per i report del DB
                        'importo' => $importoCoperturaCents * $moltiplicatore,
                        'stato' => 'pianificata',
                        // `fonte_id` è `nullable` in StoreFatturaRequest:102 e può davvero
                        // mancare: senza il `??` i rami rata_0 e fondo_riserva emettevano un
                        // «Undefined array key» a ogni pregresso senza fonte esplicita — non
                        // bloccante, ma a schermo dove `display_errors` è acceso. Il ramo
                        // sopravvenienza era già protetto: erano due su tre.
                        'saldo_id' => $copertura['tipo_copertura'] === 'rata_0' ? ($copertura['fonte_id'] ?? null) : null,
                        'conto_id' => $copertura['tipo_copertura'] === 'sopravvenienza' ? ($copertura['fonte_id'] ?? null) : null,
                        'fondo_id' => $copertura['tipo_copertura'] === 'fondo_riserva' ? ($copertura['fonte_id'] ?? null) : null,
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
                    'tipo_copertura' => 'sopravvenienza',
                    // FIX: Moltiplicatore per i report del DB
                    'importo' => $eccedenzaCents * $moltiplicatore,
                    'stato' => 'pianificata',
                    'conto_id' => $nuovoContoIdPregresso,
                    'nota_amministratore' => 'Eccedenza fattura pregressa non coperta dai saldi iniziali',
                ]);
            }

            // 5. Salvataggio File
            if ($file) {
                $path = $file->storeAs('documenti/'.$condominioId, $file->hashName(), 'local');

                // ⚠️ **Per chiave stabile, non per etichetta.** Cercava `where('name', 'Fatture')`,
                // e bastava che l'amministratore rinominasse la categoria perché ogni allegato di
                // fattura finisse in archivio **senza categoria**, in silenzio (Coda 106).
                // `perFatture()` cerca lo slug e ricade sull'etichetta, quindi trova la categoria
                // in più casi di prima e mai in meno.
                $categoriaFatture = CategoriaDocumento::perFatture();

                $documento = $fattura->documenti()->create([
                    'name' => $file->getClientOriginalName(),
                    'description' => 'Fattura passiva n. '.$data['numero_documento'],
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'created_by' => Auth::id() ?? 1,
                    'is_published' => false,
                    'is_approved' => true,
                ]);

                // ⚠️ **Il legame, non più la colonna** (1.11.0-beta.10). E resta condizionato:
                // se la categoria non c'è l'allegato si salva lo stesso, senza categoria, com'era
                // prima. Toglierlo trasformerebbe una degradazione in un errore in faccia a chi
                // sta registrando una fattura.
                if ($categoriaFatture) {
                    $documento->categorie()->attach($categoriaFatture->id);
                }
            }

            // 6. Contabilità (Partita Doppia)
            $contoDebiti = ContoContabile::where('condominio_id', $condominioId)
                ->where('ruolo', 'debiti_fornitori')
                ->first();

            $contoCreditiCondomini = ContoContabile::where('condominio_id', $condominioId)
                ->where('ruolo', 'crediti_condomini')
                ->first();

            if (! $contoDebiti || ! $contoCreditiCondomini) {
                throw new \Exception("Errore Piano dei Conti: Mancano i Mastri 'debiti_fornitori' o 'crediti_condomini'.");
            }

            $scrittura = ScritturaContabile::create([
                'condominio_id' => $condominioId,
                'esercizio_id' => $data['esercizio_id'],
                'gestione_id' => $data['gestione_id'] ?? null,
                'data_registrazione' => now(),
                'data_competenza' => $fattura->data_documento,
                'numero_protocollo' => $fattura->numero_protocollo,
                'causale' => ($isPregresso ? '[PREGRESSO] ' : '')."Ft. {$data['numero_documento']} - {$fornitore->ragione_sociale}",
                'tipo_movimento' => 'fattura_acquisto',
                'stato' => 'registrata',
            ]);

            if ($isPregresso) {
                $contoPassateGestioni = ContoContabile::where('condominio_id', $condominioId)
                    ->where('ruolo', 'passate_gestioni')
                    ->firstOrFail();

                $totaleRata0 = 0;

                if (! empty($data['coperture'])) {
                    foreach ($data['coperture'] as $copertura) {
                        $importoCoperturaCents = (int) round($copertura['importo'] * 100);

                        if ($copertura['tipo_copertura'] === 'fondo_riserva') {
                            // Beta.19: la riga DARE è strutturale (bilancia l'AVERE
                            // debiti) ma NON tocca più il conto del fondo — andava
                            // sul fondo col segno passivo, in contraddizione col suo
                            // conto attivo. Va su passate_gestioni come le coperture
                            // rata_0: il legame col fondo vive su fattura_coperture
                            // (fondo_id) e diventa reale alla conferma via giroconto.
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoPassateGestioni->id,
                                'tipo_riga' => 'dare',
                                'importo' => abs($importoCoperturaCents),
                                'note' => 'Debito pregresso con copertura da fondo (in attesa di giroconto di conferma)',
                            ]);
                        } else {
                            $totaleRata0 += $importoCoperturaCents;
                        }
                    }

                    if ($totaleRata0 > 0) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoPassateGestioni->id,
                            'tipo_riga' => 'dare',
                            'importo' => abs($totaleRata0),
                            'note' => 'Chiusura debito patrimoniale pregresso',
                        ]);
                    }
                }

                if ($eccedenzaCents > 0) {
                    if ($nuovoContoIdPregresso) {
                        $contoSopravv = Conto::find($nuovoContoIdPregresso);
                        if ($contoSopravv && $contoSopravv->conto_contabile_id) {
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoSopravv->conto_contabile_id,
                                'tipo_riga' => 'dare',
                                'importo' => abs($eccedenzaCents),
                                'voce_spesa_id' => $contoSopravv->id,
                                'note' => 'Sopravvenienza passiva: eccedenza fattura pregressa',
                            ]);
                        }
                    } else {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoPassateGestioni->id,
                            'tipo_riga' => 'dare',
                            'importo' => abs($eccedenzaCents),
                            'note' => 'Caricamento debito pregresso senza copertura esplicita',
                        ]);
                    }
                }
            } else {
                foreach ($righeProcessate as $riga) {
                    $importoLordoRiga = abs($riga['importo_imponibile'] + $riga['importo_iva']);

                    if (! empty($riga['immobile_id'])) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoCreditiCondomini->id,
                            'tipo_riga' => 'dare',
                            'importo' => $importoLordoRiga,
                            'voce_spesa_id' => null,
                            'immobile_id' => $riga['immobile_id'],
                            'note' => 'Anticipazione spesa ad personam (Art. 63)',
                        ]);
                    } elseif (! empty($riga['conto_id'])) {
                        $contoBudget = Conto::find($riga['conto_id']);
                        if ($contoBudget && $contoBudget->conto_contabile_id) {
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoBudget->conto_contabile_id,
                                'tipo_riga' => 'dare',
                                'importo' => $importoLordoRiga,
                                'voce_spesa_id' => $riga['conto_id'],
                                'immobile_id' => null,
                            ]);
                        } else {
                            throw new \Exception("Integrità compromessa: Manca l'ancoraggio in Partita Doppia per il capitolo di spesa.");
                        }
                    } else {
                        throw new \Exception('Integrità compromessa: Impossibile allocare la riga (nessun immobile e nessun conto associato).');
                    }
                }
            }

            $anagraficaPrincipale = $fornitore->referenti()->first();

            // AVERE 1: Debito verso Fornitore
            $scrittura->righe()->create([
                'conto_contabile_id' => $contoDebiti->id,
                'tipo_riga' => 'avere',
                'importo' => abs($netto),
                'anagrafica_id' => $anagraficaPrincipale ? $anagraficaPrincipale->id : null,
            ]);

            // AVERE 2: Debito verso Erario (Ritenute)
            if ($ritenuta > 0) {
                $contoErario = ContoContabile::where('condominio_id', $condominioId)
                    ->where('ruolo', 'debiti_erario_ritenute')
                    ->first();

                if (! $contoErario) {
                    throw new \Exception('Errore Piano dei Conti: Manca il Conto Mastro Ritenute.');
                }

                $scrittura->righe()->create([
                    'conto_contabile_id' => $contoErario->id,
                    'tipo_riga' => 'avere',
                    'importo' => abs($ritenuta),
                    'anagrafica_id' => $anagraficaPrincipale ? $anagraficaPrincipale->id : null,
                    'note' => $ritenutaCalcolo->nota,
                ]);
            }

            // Sforo con strategia fondo riserva — beta.19: NESSUNA riga contabile qui.
            // La coppia DARE fondo / AVERE sopravvenienze che si scriveva in questo
            // punto era una promessa senza conferma: muoveva il fondo alla
            // registrazione della fattura, prima che qualcuno decidesse davvero di
            // usarlo (e col segno passivo, in contraddizione col conto attivo del
            // fondo). Il costo dello sforo resta interamente sul capitolo (righe
            // DARE sopra): il rendiconto dice la verità. Il fondo si muove SOLO
            // quando la copertura 'pianificata' (creata più avanti in questa stessa
            // transazione) viene confermata con un giroconto fondo → banca
            // (RegistraGirocontoAction), che la porta a 'confermata' e la aggancia
            // alla scrittura GIR.

            // =====================================================================
            // IL FILTRO INVERTITORE
            // =====================================================================
            if ($isNotaCredito) {
                DB::table('righe_scritture')
                    ->where('scrittura_id', $scrittura->id)
                    ->update([
                        'tipo_riga' => DB::raw("CASE WHEN tipo_riga = 'dare' THEN 'avere' ELSE 'dare' END"),
                    ]);
            }

            // =====================================================================
            // DOUBLE-ENTRY VALIDATOR (Audit Trail & Rollback)
            // =====================================================================
            DoubleEntryValidator::validateOrFail($scrittura->id);

            $fattura->scritture()->attach($scrittura->id, [
                'importo_allocato' => abs($totaleDoc),
                'tipo' => 'competenza',
            ]);

            event(new FatturaRegistrata($fattura, Auth::id() ?? 1));

            return $fattura;
        });
    }

    /**
     * Aggiorna una fattura passiva aperta, ricreando le scritture contabili.
     *
     * Permessa solo per fatture ordinarie completamente aperte (nessun pagamento,
     * nessuna sopravvenienza, non pregresse, non in sforo pendente, esercizio aperto).
     * Per tutti gli altri casi è obbligatorio lo storno.
     *
     * Effetti collaterali:
     *  - Se `data_scadenza` cambia → aggiorna start_time del task Inbox `pagamento_fornitore`
     *  - Il numero_protocollo è immutabile (identificativo contabile)
     *  - Non riemette FatturaRegistrata (evita duplicazione task Inbox)
     *
     * @param  FatturaPassiva  $fattura  La fattura da aggiornare (con relazioni caricate).
     * @param  array  $data  I nuovi dati validati (stessa struttura di registraFattura, senza fornitore_id e tipo_documento).
     * @param  UploadedFile|null  $file  Nuovo allegato (opzionale). Se presente sostituisce il precedente.
     *
     * @throws FatturaModificaVietataException Se la fattura non può essere modificata direttamente.
     */
    public function motivoBloccoModifica(FatturaPassiva $fattura): ?string
    {
        if ($fattura->stato_pagamento === StatoPagamentoFattura::STORNATA || ($fattura->dati_extra['is_stornata'] ?? false)) {
            return 'Fattura già stornata: non modificabile.';
        }

        if ($fattura->stato_pagamento !== StatoPagamentoFattura::APERTA) {
            return 'La fattura ha già un pagamento registrato. Usa lo storno.';
        }

        $statoEsercizio = DB::table('esercizi')->where('id', $fattura->esercizio_id)->value('stato');
        if ($statoEsercizio === 'chiuso') {
            return 'La fattura appartiene a un esercizio chiuso: usa lo storno.';
        }

        if ($fattura->is_pregresso) {
            return 'Le fatture pregresse non sono modificabili direttamente: usa lo storno.';
        }

        if ($fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->exists()) {
            return 'La fattura ha coperture di sopravvenienza: usa lo storno.';
        }

        // Beta.19: la copertura fondo — pianificata o confermata — fotografa lo sforo
        // al momento della registrazione. aggiornaFattura ricrea le scritture ma non
        // le coperture: una modifica lascerebbe un importo di copertura stantio,
        // confermabile con un giroconto sbagliato.
        if ($fattura->coperture()->where('tipo_copertura', 'fondo_riserva')->exists()) {
            return 'La fattura ha una copertura dal fondo di riserva: usa lo storno e registrala di nuovo.';
        }

        if ($fattura->stato_approvazione === 'sforo_motivato') {
            return 'La fattura ha uno sforo in attesa di ratifica assembleare: usa lo storno.';
        }

        // Le strategie conguaglio/rata integrativa non creano coperture, quindi il
        // controllo sul fondo non le vede: dopo la ratifica (sforo_motivato →
        // approvata) la fattura tornava modificabile, con motivazione e importo
        // dello sforo in dati_extra riferiti a cifre che l'assemblea non ha mai
        // visto. Stessa regola della copertura: la ratifica fotografa la fattura.
        if (! empty($fattura->dati_extra['override_budget'])) {
            return 'Lo sforo di questa fattura è stato motivato e ratificato in assemblea: usa lo storno e registrala di nuovo.';
        }

        // Controllo piano rate (replica del controllo in destroy())
        $pivotPlan = DB::table('piano_rate_fatture')->where('fattura_passiva_id', $fattura->id)->first();
        if ($pivotPlan) {
            $piano = PianoRate::find($pivotPlan->piano_rate_id);
            if ($piano instanceof PianoRate) {
                $hasPagamenti = $piano->rate()->whereHas('rateQuote', fn ($q) => $q->where('importo_pagato', '>', 0))->exists();
                $hasEmissioni = $piano->rate()->whereHas('rateQuote', fn ($q) => $q->whereNotNull('scrittura_contabile_id'))->exists();
                if ($hasPagamenti || $hasEmissioni) {
                    return 'La fattura è in un piano straordinario con rate già emesse: usa lo storno.';
                }
                $stato = is_object($piano->stato) ? $piano->stato->value : $piano->stato;
                if ($stato === 'approvato') {
                    return 'La fattura è in un piano approvato: usa lo storno.';
                }
            }
        }

        return null;
    }

    public function aggiornaFattura(FatturaPassiva $fattura, array $data, ?UploadedFile $file = null): FatturaPassiva
    {
        if ($motivo = $this->motivoBloccoModifica($fattura)) {
            throw new FatturaModificaVietataException($motivo);
        }

        // ── Snapshot before (per audit trail e aggiornamento Inbox) ─────────
        $dataScadenzaBefore = $fattura->data_scadenza?->format('Y-m-d');
        $importoBefore = $fattura->netto_a_pagare;

        // ── Transazione atomica ──────────────────────────────────────────────
        return DB::transaction(function () use ($fattura, $data, $file, $dataScadenzaBefore, $importoBefore) {

            // 1. Pulizia scritture esistenti (identico a destroy())
            $fattura->load('righe', 'documenti', 'scritture', 'coperture');
            $scritture = $fattura->scritture;

            $fattura->scritture()->detach();

            foreach ($scritture as $scrittura) {
                $scrittura->righe()->delete();
                $scrittura->forceDelete();
            }

            // 2. Pulizia righe fattura
            $fattura->righe()->delete();

            // 3. Aggiorna testata fattura (campi modificabili — protocollo e fornitore sono immutabili)
            $fattura->update([
                'numero_documento' => $data['numero_documento'],
                'data_documento' => $data['data_documento'],
                'data_scadenza' => $data['data_scadenza'],
                'modalita_pagamento' => $data['modalita_pagamento'],
                'iban_fornitore' => $data['iban_fornitore'] ?? null,
                'conto_corrente_id' => $data['conto_corrente_id'] ?? $fattura->conto_corrente_id,
            ]);

            // 4. Ricalcola imponibile, IVA e ritenuta dalle nuove righe
            $fornitore = Fornitore::with('referenti')->findOrFail($fattura->fornitore_id);
            $isNotaCredito = ($fattura->tipo_documento === 'nota_credito');
            $moltiplicatore = $isNotaCredito ? -1 : 1;

            $imponibileTotale = 0;
            $ivaTotale = 0;
            $righeProcessate = [];
            $righeRitenuta = [];

            foreach ($data['righe'] as $rigaInput) {
                $impRiga = (int) round($rigaInput['importo_imponibile'] * 100);
                $aliq = (float) $rigaInput['aliquota_iva'];
                $ivaRiga = (int) round(($impRiga * $aliq) / 100);

                $imponibileTotale += $impRiga;
                $ivaTotale += $ivaRiga;

                $contoIdRiga = $rigaInput['conto_id'] ?? null;
                if (! empty($rigaInput['immobile_id'])) {
                    $contoIdRiga = null;
                }

                $righeProcessate[] = [
                    'descrizione' => $rigaInput['descrizione'],
                    'importo_imponibile' => $impRiga * $moltiplicatore,
                    'aliquota_iva' => $aliq,
                    'importo_iva' => $ivaRiga * $moltiplicatore,
                    'conto_id' => $contoIdRiga,
                    'immobile_id' => $rigaInput['immobile_id'] ?? null,
                    'is_sopravvenienza' => false, // bloccato a monte
                    'concorre_base_ritenuta' => filter_var($rigaInput['concorre_base_ritenuta'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'natura_riga_ritenuta' => $rigaInput['natura_riga_ritenuta'] ?? null,
                ];

                $righeRitenuta[] = [
                    'importo_imponibile' => $impRiga,
                    'concorre_base_ritenuta' => filter_var($rigaInput['concorre_base_ritenuta'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ];
            }

            // Ritenuta — stessa logica di registraFattura(): NC di default esclusa,
            // storno la propaga esplicitamente (design §8 punti 2 e 3).
            $richiestaApplicazioneRitenuta = $data['applica_ritenuta'] ?? ! $isNotaCredito;

            $ritenutaCalcolo = app(RitenutaService::class)->calcola(
                $fornitore,
                $righeRitenuta,
                $imponibileTotale,
                $richiestaApplicazioneRitenuta,
                Carbon::parse($data['data_documento'] ?? $fattura->data_documento),
            );

            $ritenuta = $ritenutaCalcolo->importo;
            $datiRitenuta = $ritenutaCalcolo->toLegacyDatiExtra();

            $totaleDoc = $imponibileTotale + $ivaTotale;
            $netto = ($totaleDoc - $ritenuta) * $moltiplicatore;

            // 5. Aggiorna importi sulla fattura
            $datiExtra = $fattura->dati_extra ?? [];
            $datiExtra['fiscal']['ritenuta_details'] = $datiRitenuta;
            $datiExtra['fiscal']['motivo_esclusione_ritenuta'] = $this->motivoEsclusioneRitenuta($ritenutaCalcolo, $data);

            $fattura->update([
                'importo_imponibile' => $imponibileTotale * $moltiplicatore,
                'importo_iva' => $ivaTotale * $moltiplicatore,
                'importo_ritenuta' => $ritenuta * $moltiplicatore,
                'totale_documento' => $totaleDoc * $moltiplicatore,
                'netto_a_pagare' => $netto,
                'dati_extra' => $datiExtra,
            ]);

            // 6. Ricrea righe
            $fattura->righe()->createMany($righeProcessate);

            // 7. Ricrea scrittura contabile con la stessa logica di registraFattura()
            $contoDebiti = ContoContabile::where('condominio_id', $fattura->condominio_id)
                ->where('ruolo', 'debiti_fornitori')
                ->firstOrFail();

            $contoCreditiCondomini = ContoContabile::where('condominio_id', $fattura->condominio_id)
                ->where('ruolo', 'crediti_condomini')
                ->firstOrFail();

            $scrittura = ScritturaContabile::create([
                'condominio_id' => $fattura->condominio_id,
                'esercizio_id' => $fattura->esercizio_id,
                'gestione_id' => $data['gestione_id'] ?? $fattura->scritture()->withTrashed()->first()?->gestione_id,
                'data_registrazione' => now(),
                'data_competenza' => $fattura->data_documento,
                'numero_protocollo' => $fattura->numero_protocollo, // immutabile
                'causale' => "Ft. {$fattura->numero_documento} - {$fornitore->ragione_sociale} [modifica]",
                'tipo_movimento' => 'fattura_acquisto',
                'stato' => 'registrata',
            ]);

            // Righe DARE
            $anagraficaPrincipale = $fornitore->referenti()->first();

            foreach ($righeProcessate as $riga) {
                $importoLordoRiga = abs($riga['importo_imponibile'] + $riga['importo_iva']);

                if (! empty($riga['immobile_id'])) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoCreditiCondomini->id,
                        'tipo_riga' => 'dare',
                        'importo' => $importoLordoRiga,
                        'voce_spesa_id' => null,
                        'immobile_id' => $riga['immobile_id'],
                        'note' => 'Anticipazione spesa ad personam (Art. 63)',
                    ]);
                } elseif (! empty($riga['conto_id'])) {
                    $contoBudget = Conto::find($riga['conto_id']);
                    if ($contoBudget && $contoBudget->conto_contabile_id) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoBudget->conto_contabile_id,
                            'tipo_riga' => 'dare',
                            'importo' => $importoLordoRiga,
                            'voce_spesa_id' => $riga['conto_id'],
                        ]);
                    } else {
                        throw new \Exception("Integrità compromessa: manca l'ancoraggio in Partita Doppia per il capitolo di spesa.");
                    }
                } else {
                    throw new \Exception('Integrità compromessa: impossibile allocare la riga (nessun immobile e nessun conto associato).');
                }
            }

            // Riga AVERE — Debito verso Fornitore
            $scrittura->righe()->create([
                'conto_contabile_id' => $contoDebiti->id,
                'tipo_riga' => 'avere',
                'importo' => abs($netto),
                'anagrafica_id' => $anagraficaPrincipale?->id,
            ]);

            // Riga AVERE — Debito verso Erario (se ritenuta)
            if ($ritenuta > 0) {
                $contoErario = ContoContabile::where('condominio_id', $fattura->condominio_id)
                    ->where('ruolo', 'debiti_erario_ritenute')
                    ->firstOrFail();

                $scrittura->righe()->create([
                    'conto_contabile_id' => $contoErario->id,
                    'tipo_riga' => 'avere',
                    'importo' => abs($ritenuta),
                    'anagrafica_id' => $anagraficaPrincipale?->id,
                    'note' => $ritenutaCalcolo->nota,
                ]);
            }

            // Inverti righe se nota di credito
            if ($isNotaCredito) {
                DB::table('righe_scritture')
                    ->where('scrittura_id', $scrittura->id)
                    ->update([
                        'tipo_riga' => DB::raw("CASE WHEN tipo_riga = 'dare' THEN 'avere' ELSE 'dare' END"),
                    ]);
            }

            // 8. Double-Entry Validator
            DoubleEntryValidator::validateOrFail($scrittura->id);

            // 9. Attach pivot competenza
            $fattura->scritture()->attach($scrittura->id, [
                'importo_allocato' => abs($totaleDoc),
                'tipo' => 'competenza',
            ]);

            // 10. Gestione allegato: nuovo file → elimina vecchio → salva
            if ($file) {
                foreach ($fattura->documenti as $doc) {
                    if (Storage::disk('local')->exists($doc->path)) {
                        Storage::disk('local')->delete($doc->path);
                    }
                    $doc->delete();
                }

                $path = $file->storeAs('documenti/'.$fattura->condominio_id, $file->hashName(), 'local');

                // Per chiave stabile, non per etichetta: vedi il gemello più sopra e la Coda 106.
                $categoriaFatture = CategoriaDocumento::perFatture();

                $documento = $fattura->documenti()->create([
                    'name' => $file->getClientOriginalName(),
                    'description' => 'Fattura passiva n. '.$fattura->numero_documento,
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'created_by' => Auth::id() ?? 1,
                    'is_published' => false,
                    'is_approved' => true,
                ]);

                // Il legame, non più la colonna: vedi il gemello più sopra.
                if ($categoriaFatture) {
                    $documento->categorie()->attach($categoriaFatture->id);
                }
            }

            // 11. Aggiorna task Inbox `pagamento_fornitore` se data_scadenza cambiata
            $nuovaScadenza = $fattura->fresh()->data_scadenza;
            if ($nuovaScadenza && $dataScadenzaBefore !== $nuovaScadenza->format('Y-m-d')) {
                Evento::where('meta->context->fattura_id', $fattura->id)
                    ->where('meta->type', 'pagamento_fornitore')
                    ->where('is_completed', false)
                    ->update(['start_time' => $nuovaScadenza->setTime(9, 0)]);
            }

            // 12. Audit trail
            Log::info("FatturaPassiva #{$fattura->id} modificata da utente #".(Auth::id() ?? 0), [
                'netto_a_pagare_before' => $importoBefore,
                'netto_a_pagare_after' => $fattura->fresh()->netto_a_pagare,
                'data_scadenza_before' => $dataScadenzaBefore,
                'data_scadenza_after' => $nuovaScadenza?->format('Y-m-d'),
            ]);

            return $fattura->fresh();
        });
    }

    /**
     * Motivo da salvare in dati_extra.fiscal.motivo_esclusione_ritenuta. Il
     * forfetario è un fatto accertato dal servizio (vince sempre); negli
     * altri casi di ritenuta non applicata è quanto l'utente ha dichiarato
     * in fase di registrazione (validato da StoreFatturaRequest/UpdateFatturaRequest).
     */
    private function motivoEsclusioneRitenuta(RitenutaCalcolo $calcolo, array $data): ?string
    {
        if ($calcolo->applicata) {
            return null;
        }

        return $calcolo->motivoEsclusione?->value
            ?? $data['dati_extra']['fiscal']['motivo_esclusione_ritenuta'] ?? null;
    }

    /**
     * Metodo Helper: Crea un Capitolo (Conto) al volo per gestire le Sopravvenienze COMUNI.
     */
    private function creaContoDinamicoSopravvenienza(int $condominioId, int $gestioneId, int $fornitoreId, array $logLegale, int $importoCent = 0): int
    {
        $pianoConto = PianoConto::where('gestione_id', $gestioneId)->first();
        if (! $pianoConto) {
            throw new \Exception('Nessun Piano dei Conti trovato per la gestione ID: '.$gestioneId);
        }

        $contoContabileSopravvenienza = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominioId, 'ruolo' => 'sopravvenienze_passive'],
            [
                'codice' => 'SOP-'.$condominioId,
                'nome' => 'Sopravvenienze passive',
                'tipo' => ContoContabileTipo::COSTO->value,
                'categoria' => ContoContabileCategoria::COSTI->value,
                'descrizione' => 'Costi imprevisti o relativi a esercizi precedenti. Art. 1130-bis c.c.',
                'di_sistema' => true,
                'attivo' => true,
                'livello' => 1,
            ]
        );

        $capitoloPadre = Conto::firstOrCreate(
            [
                'piano_conto_id' => $pianoConto->id,
                'nome' => 'Integrazioni Straordinarie (Scudo Legale)',
                'parent_id' => null,
            ],
            [
                'tipo' => 'spesa',
                'importo' => 0,
                'descrizione' => 'Capitoli generati automaticamente dal sistema.',
                'is_tecnico' => true,
            ]
        );

        $origine = isset($logLegale['origine_decisionale'])
            ? ucfirst(strtolower(str_replace('_', ' ', $logLegale['origine_decisionale'])))
            : 'Gestione corrente';

        $nuovoConto = Conto::create([
            'piano_conto_id' => $pianoConto->id,
            'parent_id' => $capitoloPadre->id,
            'default_fornitore_id' => $fornitoreId,
            'nome' => $logLegale['nome_voce'] ?? 'Spesa Imprevista',
            'tipo' => 'spesa',
            'importo' => $importoCent,
            'tipo_ripartizione' => $logLegale['tipo_ripartizione'] ?? 'millesimale',
            'origine_decisionale' => $logLegale['origine_decisionale'] ?? 'gestione_corrente',
            'is_tecnico' => true,
            'note' => implode("\n", array_filter([
                'Origine delibera: '.$origine,
                ! empty($logLegale['motivazione_sforo'])
                    ? 'Motivazione: '.$logLegale['motivazione_sforo']
                    : null,
            ])),
            'conto_contabile_id' => $contoContabileSopravvenienza->id,
        ]);

        if (($logLegale['tipo_ripartizione'] ?? 'millesimale') === 'millesimale' && ! empty($logLegale['tabella_millesimale_id'])) {
            $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
                'conto_id' => $nuovoConto->id,
                'tabella_id' => $logLegale['tabella_millesimale_id'],
                'coefficiente' => 100.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ripartizioni = [
                ['soggetto' => 'proprietario',  'percentuale' => $logLegale['percentuale_proprietario'] ?? 0],
                ['soggetto' => 'inquilino',     'percentuale' => $logLegale['percentuale_inquilino'] ?? 0],
                ['soggetto' => 'usufruttuario', 'percentuale' => $logLegale['percentuale_usufruttuario'] ?? 0],
            ];

            if (array_sum(array_column($ripartizioni, 'percentuale')) != 100) {
                $ripartizioni = [['soggetto' => 'proprietario', 'percentuale' => 100]];
            }

            foreach ($ripartizioni as $rip) {
                if ($rip['percentuale'] > 0) {
                    DB::table('conto_tabella_ripartizioni')->insert([
                        'conto_tabella_millesimale_id' => $contoTabellaId,
                        'soggetto' => $rip['soggetto'],
                        'percentuale' => $rip['percentuale'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return $nuovoConto->id;
    }
}
