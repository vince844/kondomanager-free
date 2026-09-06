<?php

namespace App\Services\Gestionale;

use App\Helpers\MoneyHelper;
use App\DataTransferObjects\RitenutaCalcolo;
use App\Enums\ContoContabileCategoria;
use App\Enums\ContoContabileTipo;
use App\Enums\StatoPagamentoFattura;
use App\Events\Gestionale\FatturaRegistrata;
use App\Exceptions\Pagamenti\FatturaModificaVietataException;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
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

            // L'imposta che il documento dichiara per ogni gruppo IVA, gia ripartita fra le
            // righe che vi appartengono. Vuota quando il documento non dichiara i propri
            // riepiloghi (fattura digitata a mano): allora si calcola come si e sempre fatto.
            $ivaDistribuita = $this->distribuisciImpostaDichiarata($data);

            $imponibileTotale = 0;
            $ivaTotale = 0;
            $righeProcessate = [];
            $righeRitenuta = [];

            $dynamicContoComuneId = null;
            $logLegale = $data['dati_extra']['log_legale_sopravvenienza'] ?? null;

            // 1. Calcolo Imponibile e IVA
            if ($isPregresso) {
                $impPregresso = (int) round(($data['imponibile_pregresso'] ?? 0) * 100);
                $aliqPregressa = (float) ($data['aliquota_iva_pregressa'] ?? 22);
                // ⚠️ **Se il documento dichiara la propria imposta, quella vince sull'aliquota.**
                // Il pannello del debito pregresso ha un campo solo, quindi l'importazione gli
                // passa l'aliquota MEDIA del documento, arrotondata a due decimali. Su un
                // documento a piu aliquote quella media non ricostruisce l'imposta vera, ed e
                // una perdita di precisione in piu rispetto al calcolo per riga.
                // ⚠️ **Misurato l'06/09/2026: sugli undici file di collaudo la ricostruzione e
                // esatta in tutti e undici**, quindi questa correzione non cambia nessuno di
                // quei numeri. Ma il caso divergente esiste ed e costruibile -- imponibile
                // 100,05 con imposta 10,00 da un'aliquota media di 10,00 che ricostruisce
                // 10,01, un centesimo inventato su un debito che resta a bilancio. Sei degli
                // undici file passano da questo ramo, quindi la superficie e reale anche se
                // il corpus non la colpisce. `imposta_dichiarata` viaggiava gia nel payload
                // dalla beta.18 e veniva buttata.
                $ivaPregressa = isset($data['imposta_pregressa'])
                    ? (int) round($data['imposta_pregressa'] * 100)
                    : (int) round(($impPregresso * $aliqPregressa) / 100);

                $imponibileTotale = $impPregresso;
                $ivaTotale = $ivaPregressa;
            } else {
                foreach ($data['righe'] as $indiceRiga => $rigaInput) {
                    $impRiga = (int) round($rigaInput['importo_imponibile'] * 100);
                    $aliq = (float) $rigaInput['aliquota_iva'];
                    // Se il documento dichiara la propria imposta, quella vince: la somma degli
                    // arrotondamenti di riga non e l'imposta del documento, ed e la differenza
                    // fra 10,05 e 10,06 sulla bolletta del file 06 dei collaudi.
                    // Priorita: (1) l'imposta dichiarata dal documento, distribuita; (2) quella che
                    // il chiamante impone riga per riga -- e lo storno, che deve essere lo specchio
                    // esatto della fattura e non una sua riapprossimazione; (3) il calcolo per riga
                    // di sempre, per la fattura digitata a mano.
                    $ivaRiga = $ivaDistribuita[$indiceRiga]
                        ?? (isset($rigaInput['importo_iva_dichiarata'])
                            ? (int) round($rigaInput['importo_iva_dichiarata'] * 100)
                            : (int) round(($impRiga * $aliq) / 100));
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
                // ⚠️ **Qui c'erano `imponibile_pregresso` e `aliquota_iva_pregressa`, e non
                // facevano niente** (Fase 1-bis della beta.18, rilievo 4). Non sono colonne di
                // `fatture_passive` — 29 colonne, nessuna migrazione le crea — e il modello ha
                // `$guarded = ['id']`, quindi Eloquent le scartava in silenzio: l'INSERT non le
                // conteneva, e chi le rileggeva otteneva `null`. Lo storno di una pregressa le
                // leggeva davvero (`StornoFatturaController`), otteneva zero, e generava una
                // nota di credito vuota. Tolte invece di aggiungere due colonne: il dato non si
                // perde, sta già in `importo_imponibile` e `importo_iva`, e lo storno lo legge
                // da lì. Una riga che finge di salvare è peggio di una riga che manca.
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
                            // L'imposta che il documento dichiara per gruppo, conservata perché
                            // modifica e storno la ritrovino invece di ricalcolarla dalle righe.
                            // Nessuna migrazione: `dati_extra` è una colonna json che esiste già.
                            'riepiloghi_dichiarati' => $data['riepiloghi']
                                ?? $data['dati_extra']['fiscal']['riepiloghi_dichiarati']
                                ?? null,
                            // Stessa ragione, per il ramo del debito pregresso: senza questa la
                            // nota di credito di uno storno ricostruirebbe l'imposta da
                            // un'aliquota media arrotondata invece che dal numero dichiarato.
                            'imposta_pregressa_dichiarata' => $data['imposta_pregressa']
                                ?? $data['dati_extra']['fiscal']['imposta_pregressa_dichiarata']
                                ?? null,
                        ]
                    ),
                    'competenza' => $data['dati_extra']['competenza'] ?? null,
                    'override_budget' => $data['dati_extra']['override_budget'] ?? null,
                    // ⚠️ **Coda 124, 04/09/2026.** Questo array sostituisce interamente
                    // `dati_extra`: qualunque chiave che il chiamante avesse messo lì e che non
                    // sia elencata qui viene persa in silenzio. `StornoFatturaController` passa
                    // `nota_storno` per marcare la nota di credito generata dallo storno come
                    // «non è un documento vero, non compensarla» — e quella chiave arrivava fin
                    // qui per essere scartata. `PagamentoFornitoreService::trovaNoteCreditoCompensabili()`
                    // filtra su questa chiave (`whereNull('dati_extra->nota_storno')`), quindi la
                    // guardia era sempre vera: la nota da storno rientrava fra quelle offerte in
                    // compensazione, e una fattura vera poteva risultare pagata senza che un euro
                    // uscisse dalla cassa. Misurato con una sonda end-to-end il 04/09/2026, prima
                    // di questa correzione.
                    'nota_storno' => $data['dati_extra']['nota_storno'] ?? null,
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
                $this->creaDocumentoFattura($fattura, $file);
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
                    // ⚠️ **Si rimoltiplica per `$moltiplicatore`, e non è un refuso.** Le righe
                    // in `$righeProcessate` hanno il segno del DOCUMENTO già applicato (righe
                    // 97 e 99): su una nota di credito una riga digitata positiva è qui
                    // negativa. Ma il verso della nota lo dà già «il filtro invertitore» in
                    // fondo a questo metodo, che gira l'intera scrittura in un colpo solo.
                    // Quello che serve qui è il segno **naturale** della riga — quello che
                    // l'amministratore ha digitato — altrimenti su una NC ogni riga verrebbe
                    // girata due volte. Poiché il moltiplicatore vale ±1, rimoltiplicare lo
                    // annulla ed è esatto al centesimo.
                    $importoSegnatoRiga = ($riga['importo_imponibile'] + $riga['importo_iva']) * $moltiplicatore;
                    $importoLordoRiga = abs($importoSegnatoRiga);

                    // ⚠️ **Una riga da € 0,00 non produce nessuna scrittura, e non è un caso
                    // di confine: gli XML veri ne sono pieni.** Cinque degli undici file di
                    // collaudo portano righe puramente descrittive — numeri di protocollo,
                    // riferimenti tecnici, intestazioni che introducono le voci successive —
                    // con `PrezzoTotale` a 0.00. Sono contenuto del documento e restano in
                    // `righe_fattura`; ma un DARE di € 0,00 non è una scrittura, è rumore, e
                    // senza questa guardia una riga a zero e senza capitolo finiva nel ramo
                    // «Impossibile allocare la riga» qui sotto: 500 con rollback, e l'intera
                    // fattura persa (Fase 1-bis, reperto 3).
                    //
                    // Il confronto è con lo ZERO esatto, non con `<= 0`: una riga
                    // legittimamente **negativa** — il conguaglio sugli oneri di sistema del
                    // file 06 del collaudo — deve continuare a produrre la sua scrittura.
                    if ($importoLordoRiga === 0) {
                        continue;
                    }

                    // ⚠️ **Il segno della riga sceglie il LATO, non si butta via.** Fino alla
                    // beta.17 qui c'era `abs()` e basta, e ogni riga finiva in DARE con il suo
                    // valore assoluto: una riga negativa — lo storno «Oneri di sistema» che
                    // ogni bolletta gas porta dentro una fattura ordinaria — invece di
                    // *ridurre* il DARE lo *aumentava*. L'AVERE, che nasce da `$netto` cioè da
                    // una somma già con segno, restava giusto: da lì lo sbilancio, pari a due
                    // volte la riga negativa, e `DoubleEntryValidator` respingeva l'intera
                    // fattura («Sbilancio rilevato tra DARE e AVERE»). La guardia dello zero
                    // qui sopra dichiarava già legittima la riga negativa; mancava il passo
                    // dopo. In partita doppia l'importo è sempre positivo ed è `tipo_riga` a
                    // portare il verso: una riga negativa è un AVERE **sullo stesso conto**,
                    // che è anche il modo in cui il resto del sistema già la legge
                    // (`SpesaPerVoceService` netta dare − avere per capitolo).
                    $versoRiga = $importoSegnatoRiga < 0 ? 'avere' : 'dare';

                    if (! empty($riga['immobile_id'])) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoCreditiCondomini->id,
                            'tipo_riga' => $versoRiga,
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
                                'tipo_riga' => $versoRiga,
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

            // ⚠️ **Anche la testata sceglie il lato dal segno, non solo le righe di dettaglio.**
            // Dare il verso calcolato alle righe e lasciarlo fisso qui è mezza correzione, e si
            // rompe sul documento che vale meno di zero: la bolletta in cui il conguaglio a
            // credito supera il consumo del periodo (riga +€ 50,00 e storno −€ 100,00). Lì le
            // righe scrivono DARE 6100 / AVERE 12200, e una testata cablata su «avere»
            // aggiungerebbe altri 6100 dalla parte sbagliata: ricomparirebbe «Sbilancio
            // rilevato», cioè il messaggio che questa beta esiste per far sparire, spostato dal
            // livello della riga a quello del documento (Fase 1-bis, rilievo 3).
            //
            // Il segno da guardare è quello **naturale** — prima del moltiplicatore — per la
            // stessa ragione delle righe: il verso della nota di credito lo dà l'invertitore
            // qui sotto, e leggere `$netto` (già moltiplicato) la farebbe girare due volte.
            $nettoNaturale = $totaleDoc - $ritenuta;

            // Debito verso Fornitore — AVERE su una fattura normale, DARE quando il documento
            // è a credito perché gli storni di riga superano gli addebiti.
            $scrittura->righe()->create([
                'conto_contabile_id' => $contoDebiti->id,
                'tipo_riga' => $nettoNaturale < 0 ? 'dare' : 'avere',
                'importo' => abs($nettoNaturale),
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

    public function aggiornaFattura(FatturaPassiva $fattura, array $data): FatturaPassiva
    {
        if ($motivo = $this->motivoBloccoModifica($fattura)) {
            throw new FatturaModificaVietataException($motivo);
        }

        // ── Snapshot before (per audit trail e aggiornamento Inbox) ─────────
        $dataScadenzaBefore = $fattura->data_scadenza?->format('Y-m-d');
        $importoBefore = $fattura->netto_a_pagare;

        // ── Transazione atomica ──────────────────────────────────────────────
        return DB::transaction(function () use ($fattura, $data, $dataScadenzaBefore, $importoBefore) {

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

            // L'imposta che il documento dichiara per ogni gruppo IVA, gia ripartita fra le
            // righe che vi appartengono. Vuota quando il documento non dichiara i propri
            // riepiloghi (fattura digitata a mano): allora si calcola come si e sempre fatto.
            $ivaDistribuita = $this->distribuisciImpostaDichiarata($data, $fattura);

            $imponibileTotale = 0;
            $ivaTotale = 0;
            $righeProcessate = [];
            $righeRitenuta = [];

            foreach ($data['righe'] as $indiceRiga => $rigaInput) {
                $impRiga = (int) round($rigaInput['importo_imponibile'] * 100);
                $aliq = (float) $rigaInput['aliquota_iva'];
                // Se il documento dichiara la propria imposta, quella vince: la somma degli
                // arrotondamenti di riga non e l'imposta del documento, ed e la differenza
                // fra 10,05 e 10,06 sulla bolletta del file 06 dei collaudi.
                // Priorita: (1) l'imposta dichiarata dal documento, distribuita; (2) quella che
                // il chiamante impone riga per riga -- e lo storno, che deve essere lo specchio
                // esatto della fattura e non una sua riapprossimazione; (3) il calcolo per riga
                // di sempre, per la fattura digitata a mano.
                $ivaRiga = $ivaDistribuita[$indiceRiga]
                    ?? (isset($rigaInput['importo_iva_dichiarata'])
                        ? (int) round($rigaInput['importo_iva_dichiarata'] * 100)
                        : (int) round(($impRiga * $aliq) / 100));

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

            // ⚠️ **Coda 123, 04/09/2026.** Una NC nata da uno storno porta una ritenuta
            // GIÀ CONGELATA sull'importo reale dell'originale (`StornoFatturaController`
            // passa `ritenuta_override` a `registraFattura()`, che la persiste in
            // `dati_extra.fiscal.ritenuta_details` — vedi il commento lì). Ricalcolarla
            // qui con `RitenutaService::calcola()` interroga l'anagrafica **attuale** del
            // fornitore: se cambia fra lo storno e questa modifica (es. diventa
            // forfetario), un salvataggio che tocca solo una descrizione o una data
            // ricalcolerebbe una ritenuta diversa da quella davvero registrata — riaprendo
            // lo stesso residuo fantasma su 2201/2202 che l'override dello storno
            // (design §8 punto 2) doveva chiudere, stavolta dalla porta della modifica
            // invece che da quella dello storno. Il congelamento si applica solo se
            // l'imponibile non è cambiato: una NC da storno non dovrebbe mai vedere le
            // proprie righe alterate in importo (il suo imponibile è per definizione lo
            // specchio dell'originale), ma se succede il ricalcolo dal vivo resta l'unica
            // opzione sensata, perché un importo congelato non avrebbe più relazione con
            // un imponibile diverso da quello per cui era stato calcolato.
            //
            // ⚠️ **La fonte di verità è la fattura ORIGINALE, non la nota stessa** — e non è
            // un dettaglio di stile: è la correzione di un difetto che la prima stesura di
            // questa coda aveva introdotto, trovato dalla revisione avversariale del
            // 05/09/2026 e provato con una sonda. Leggendo i campi della nota si costruiva
            // una tagliola su DUE salvataggi: il primo con l'imponibile alterato passava dal
            // ramo dal vivo e — poiché il passo 5 riscrive `ritenuta_details` e
            // `importo_ritenuta` in ogni caso — cancellava il valore congelato dalla nota;
            // il secondo, tornato all'imponibile giusto, congelava lo ZERO appena scritto,
            // rendendo **permanente** il residuo fantasma sul 2202 che questa coda esiste per
            // impedire. Misurato: ritenuta 0 e € 40,00 di AVERE senza contropartita.
            // L'originale non ha questo problema perché è immodificabile per costruzione —
            // `motivoBloccoModifica()` rifiuta ogni fattura già stornata — quindi il valore
            // congelato resta recuperabile anche dopo un salvataggio uscito dal binario.
            $eNotaDaStorno = ! empty($fattura->dati_extra['nota_storno'] ?? null);

            $originaleStornato = $eNotaDaStorno
                ? FatturaPassiva::where('condominio_id', $fattura->condominio_id)
                    ->where('dati_extra->stornata_da_id', $fattura->id)
                    ->first()
                : null;

            // Il ripiego sulla nota serve alle note nate prima che lo storno scrivesse
            // `stornata_da_id`: peggiore dell'originale, ma meglio del ricalcolo dal vivo,
            // che su un fornitore cambiato azzera la ritenuta in silenzio.
            $fonteCongelamento = $originaleStornato ?? $fattura;

            if ($eNotaDaStorno && ! $originaleStornato) {
                Log::warning('Coda 123: nota da storno senza originale raggiungibile, congelamento letto dai campi della nota', [
                    'fattura_id' => $fattura->id,
                ]);
            }

            $imponibileOriginale = abs((int) $fonteCongelamento->importo_imponibile);

            if ($eNotaDaStorno && $imponibileTotale === $imponibileOriginale) {
                $dettagliCongelati = $fonteCongelamento->dati_extra['fiscal']['ritenuta_details'] ?? [];
                $ritenutaCalcolo = RitenutaCalcolo::override(
                    abs((int) $fonteCongelamento->importo_ritenuta),
                    isset($dettagliCongelati['aliquota']) ? (float) $dettagliCongelati['aliquota'] : null,
                    $dettagliCongelati['codice_tributo'] ?? null,
                    isset($dettagliCongelati['imponibile_calcolo']) ? (int) $dettagliCongelati['imponibile_calcolo'] : null,
                );
            } else {
                if ($eNotaDaStorno) {
                    // Caso anomalo: le righe di una NC da storno sono state alterate in
                    // importo. Non blocchiamo il salvataggio — non è lo scenario che
                    // questa coda chiude — ma il ricalcolo dal vivo che segue non è più
                    // protetto dal congelamento, e vale la pena saperlo se succede.
                    // Non è più una condanna definitiva: rimettendo l'imponibile
                    // dell'originale, il salvataggio successivo ritrova il valore congelato
                    // là dove nessuno può averlo toccato.
                    Log::warning('Coda 123: NC da storno modificata con imponibile diverso dall\'originale, ritenuta ricalcolata dal vivo', [
                        'fattura_id' => $fattura->id,
                        'imponibile_originale' => $imponibileOriginale,
                        'imponibile_nuovo' => $imponibileTotale,
                    ]);
                }

                $ritenutaCalcolo = app(RitenutaService::class)->calcola(
                    $fornitore,
                    $righeRitenuta,
                    $imponibileTotale,
                    $richiestaApplicazioneRitenuta,
                    Carbon::parse($data['data_documento'] ?? $fattura->data_documento),
                );
            }

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
                // ⚠️ Rimoltiplicazione per `$moltiplicatore` come nella registrazione: qui le
                // righe hanno già il segno del documento, e il verso della nota di credito lo
                // dà l'invertitore in fondo. Serve il segno naturale della riga — vedi il
                // commento esteso in `registraFattura()`.
                $importoSegnatoRiga = ($riga['importo_imponibile'] + $riga['importo_iva']) * $moltiplicatore;
                $importoLordoRiga = abs($importoSegnatoRiga);

                // ⚠️ Stessa guardia della registrazione, e per lo stesso motivo: una riga da
                // € 0,00 — quelle puramente descrittive che gli XML veri portano — non produce
                // nessuna scrittura, e senza questo `continue` finiva nel ramo «impossibile
                // allocare la riga» qui sotto. Qui pesa di più che nella registrazione: una
                // fattura importata con una riga descrittiva sarebbe diventata **non
                // modificabile**, perché ogni tentativo di salvataggio esplodeva (Fase 1-bis,
                // reperto 3, avvertenza del critico avversariale).
                if ($importoLordoRiga === 0) {
                    continue;
                }

                // ⚠️ Stesso verso della registrazione, e per la stessa ragione — vedi il
                // commento lungo in `registraFattura()`. Qui però il difetto mordeva due
                // volte: una fattura con una riga negativa non solo non si registrava, ma
                // nemmeno si **rimodificava**, perché `aggiornaFattura()` ricostruisce la
                // scrittura da zero e ripassava dallo stesso `abs()`.
                $versoRiga = $importoSegnatoRiga < 0 ? 'avere' : 'dare';

                if (! empty($riga['immobile_id'])) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoCreditiCondomini->id,
                        'tipo_riga' => $versoRiga,
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
                            'tipo_riga' => $versoRiga,
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

            // Riga Debito verso Fornitore — stesso verso calcolato della registrazione, e per
            // la stessa ragione: vedi il commento esteso in `registraFattura()`.
            $nettoNaturale = $totaleDoc - $ritenuta;

            $scrittura->righe()->create([
                'conto_contabile_id' => $contoDebiti->id,
                'tipo_riga' => $nettoNaturale < 0 ? 'dare' : 'avere',
                'importo' => abs($nettoNaturale),
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

            // 10. Aggiorna task Inbox `pagamento_fornitore` se data_scadenza cambiata
            //
            // ⚠️ La gestione dell'allegato NON vive più qui (Coda 102, 1.11.0-beta.12):
            // allegare un documento passava da questo metodo, che riscrive tutte le
            // scritture contabili per un file — e il ramo cancellava ogni allegato
            // esistente prima di salvarne uno solo. I due difetti sono la stessa causa:
            // l'allegato era saldato al form contabile. Ora si allega da
            // FatturaPassivaService::aggiungiDocumento(), che non tocca contabilità né
            // allegati preesistenti — vedi FatturaPassivaController::storeDocumento().
            $nuovaScadenza = $fattura->fresh()->data_scadenza;
            if ($nuovaScadenza && $dataScadenzaBefore !== $nuovaScadenza->format('Y-m-d')) {
                Evento::where('meta->context->fattura_id', $fattura->id)
                    ->where('meta->type', 'pagamento_fornitore')
                    ->where('is_completed', false)
                    ->update(['start_time' => $nuovaScadenza->setTime(9, 0)]);
            }

            // 11. Audit trail
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
     * Allega un documento a una fattura già registrata (Coda 102, 1.11.0-beta.12).
     *
     * Deliberatamente FUORI dalla transazione contabile: un allegato non è un fatto
     * contabile e non deve costare né una riscrittura delle scritture né passare dalla
     * guardia `motivoBloccoModifica()`. È il motivo per cui questo metodo non chiama
     * `aggiornaFattura()` — chiamarlo per un solo file avrebbe voluto dire tornare
     * esattamente al difetto che risolve.
     *
     * Nessuna guardia sullo stato della fattura, di proposito: un documento è una prova,
     * non riscrive un bilancio. Consentito anche a esercizio chiuso — decisione di
     * Vincenzo, 01/09/2026.
     *
     * Non cancella gli allegati esistenti (a differenza del vecchio ramo in
     * aggiornaFattura()): morphMany regge già più righe, il difetto era solo qui.
     */
    public function aggiungiDocumento(FatturaPassiva $fattura, UploadedFile $file): Documento
    {
        return $this->creaDocumentoFattura($fattura, $file);
    }

    /**
     * Il salvataggio vero e proprio, condiviso fra registraFattura() e
     * aggiungiDocumento(): stesso storage, stessa categoria per chiave stabile
     * (Coda 106), stesso fallback silenzioso se la categoria manca.
     */
    private function creaDocumentoFattura(FatturaPassiva $fattura, UploadedFile $file): Documento
    {
        $path = $file->storeAs('documenti/'.$fattura->condominio_id, $file->hashName(), 'local');

        // ⚠️ **`storeAs()` non solleva se scrive fallisce — restituisce `false`.**
        // Il disco `local` ha `'throw' => false` (config/filesystems.php): un volume
        // pieno o una cartella non scrivibile dopo un redeploy non lanciano
        // un'eccezione, e senza questo controllo `false` finiva a database come
        // `path`, MySQL lo scriveva come stringa "0", e l'amministratore leggeva
        // «Documento allegato con successo» per un file che non esiste da nessuna
        // parte. Trovato dalla revisione avversariale della beta.12, con una sonda
        // che ha riprodotto esattamente questo.
        if ($path === false) {
            throw new \RuntimeException('Impossibile scrivere il file sul disco.');
        }

        try {
            // ⚠️ **Per chiave stabile, non per etichetta.** Cercava `where('name', 'Fatture')`,
            // e bastava che l'amministratore rinominasse la categoria perché ogni allegato di
            // fattura finisse in archivio **senza categoria**, in silenzio (Coda 106).
            // `perFatture()` cerca lo slug e ricade sull'etichetta, quindi trova la categoria
            // in più casi di prima e mai in meno.
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

            // ⚠️ **Il legame, non più la colonna** (1.11.0-beta.10). E resta condizionato:
            // se la categoria non c'è l'allegato si salva lo stesso, senza categoria, com'era
            // prima. Toglierlo trasformerebbe una degradazione in un errore in faccia a chi
            // sta allegando un documento.
            if ($categoriaFatture) {
                $documento->categorie()->attach($categoriaFatture->id);
            }
        } catch (\Throwable $e) {
            // ⚠️ **Il file è già sul disco quando questo blocco può fallire.** Senza
            // questo cleanup, un INSERT respinto da MySQL (per esempio un nome file
            // con byte non-UTF-8: errore 1366, trovato dalla revisione avversariale
            // della beta.12) lascia un file orfano che nessuna riga punta più — e
            // ogni tentativo dell'amministratore, dopo l'errore mostrato, ne lascia
            // un altro.
            Storage::disk('local')->delete($path);

            throw $e;
        }

        return $documento;
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

    /**
     * Ripartisce fra le righe l'imposta che il documento dichiara nei propri `DatiRiepilogo`.
     *
     * **Perché non basta calcolarla riga per riga.** La fattura elettronica dichiara l'imposta
     * per ogni coppia aliquota/natura, ed è quello il numero che il fornitore chiede. Calcolarla
     * riga per riga significa arrotondare N volte e poi sommare, invece di sommare e arrotondare
     * una volta sola: sul file 06 dei collaudi le tre righe al 22 % (40,61 · 6,93 · −1,80) danno
     * 8,93 + 1,52 − 0,40 = **10,05**, mentre l'imponibile del gruppo (45,74) al 22 % fa **10,06**.
     * Il documento vale € 100,15 e veniva registrato a € 100,14.
     *
     * La regola era già scritta nel progetto e il motore la contraddiceva: il docblock di
     * `FatturaPaFattura::imponibileDichiaratoCents()` dice «è questo il numero da usare per
     * registrare», quello di `sommaRigheCents()` dice «usare questo per registrare è un difetto,
     * non una scorciatoia».
     *
     * **Tre guardie, e nessuna è teorica.**
     *
     * 1. **Peso zero, imposta zero, sempre.** Una riga descrittiva a € 0,00 — i file 04 e 09 ne
     *    hanno — non deve mai ricevere un centesimo: una riga con lordo zero non produce
     *    scrittura contabile, ma con un centesimo di IVA la produrrebbe **senza capitolo**, e
     *    finirebbe nel ramo «impossibile allocare la riga»: 500 con rollback, fattura persa.
     * 2. **Gruppo che non si può normalizzare.** Se le righe di un gruppo sommano a zero, non
     *    c'è proporzione da rispettare: quel gruppo torna al calcolo per riga.
     * 3. **Gruppo senza riepilogo, o riepilogo senza righe.** Si tocca solo ciò che combacia;
     *    tutto il resto resta com'era. Verificato il 06/09/2026 sugli undici file di collaudo:
     *    raggruppando per (aliquota, natura) l'imponibile ricostruito coincide con quello
     *    dichiarato in **tutti e undici**, contributi cassa previdenziale compresi.
     *
     * ⚠️ **La somma delle imposte distribuite vale esattamente l'imposta del gruppo**, perché
     * `distribuisciPesiNormalizzati()` lo garantisce. Non è un dettaglio estetico: `$ivaTotale`
     * finisce nella riga di testata della scrittura contabile, e se le righe non sommassero a
     * quel numero `DoubleEntryValidator` respingerebbe l'intera fattura.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>  [indice della riga => imposta in centesimi]. Vuoto se il
     *                          documento non dichiara riepiloghi utilizzabili.
     */
    private function distribuisciImpostaDichiarata(array $data, ?FatturaPassiva $esistente = null): array
    {
        // ⚠️ **La modifica non riceve il file, quindi non riceve i riepiloghi.** Senza questo
        // ripiego una fattura importata a € 100,15 tornerebbe a € 100,14 al primo salvataggio —
        // bastava correggere una data. È la firma esatta del difetto ② della beta.18: nessun
        // avviso, contabilità in quadratura, importo diverso. Per questo l'imposta dichiarata
        // si conserva in `dati_extra.fiscal.riepiloghi_dichiarati` alla registrazione e si
        // rilegge da lì, invece di essere ricalcolata.
        $riepiloghi = $data['riepiloghi']
            ?? $esistente?->dati_extra['fiscal']['riepiloghi_dichiarati']
            ?? null;
        if (! is_array($riepiloghi) || $riepiloghi === [] || ! isset($data['righe']) || ! is_array($data['righe'])) {
            return [];
        }

        // La chiave del gruppo è la COPPIA aliquota/natura, non la sola aliquota: il tracciato
        // dichiara un riepilogo per coppia, e due blocchi a 0 % con nature diverse sono due
        // gruppi distinti — il file 06 ne ha uno al 22 % e uno a 0 %/N2.2.
        $chiave = static fn ($aliquota, $natura): string => number_format((float) $aliquota, 2, '.', '')
            . '|' . ($natura === null || $natura === '' ? '' : (string) $natura);

        // ⚠️ **Si SOMMA, non si assegna.** Il tracciato ammette piu' blocchi `DatiRiepilogo` sulla
        // stessa coppia (`maxOccurs="unbounded"`, nessun vincolo di unicita' nello schema): due
        // blocchi che differiscono solo per `RiferimentoNormativo` sono legittimi. Con
        // un'assegnazione sopravviveva solo l'ultimo, e l'imposta degli altri spariva.
        //
        // La ragione decisiva non e' pero' quale file possa arrivare: e' che
        // `FatturaPaFattura::impostaDichiarataCents()` somma gia' TUTTI i blocchi con
        // `array_sum`, ed e' quel numero che l'elenco dei file mostra. Sullo stesso documento
        // l'elenco avrebbe detto € 183,00 e la registrazione € 161,00 — due strade dello stesso
        // codice che si contraddicono. Trovato dalla Fase 1-bis della beta.19, da sei lenti.
        $impostaPerGruppo = [];
        $imponibileDichiaratoPerGruppo = [];
        $gruppiSenzaImponibile = [];
        foreach ($riepiloghi as $gruppo) {
            if (! isset($gruppo['aliquota_iva'])) {
                continue;
            }
            $k = $chiave($gruppo['aliquota_iva'], $gruppo['natura'] ?? null);
            $impostaPerGruppo[$k] = ($impostaPerGruppo[$k] ?? 0) + (int) round(($gruppo['imposta'] ?? 0) * 100);

            // ⚠️ **L'assenza dell'imponibile non è uno zero.** Se il gruppo non lo dichiara non
            // abbiamo il metro per giudicare se l'imposta descriva ancora queste righe, e la
            // guardia 4 va semplicemente non applicata — trattare l'assenza come zero la farebbe
            // scattare sempre, spegnendo l'imposta dichiarata su ogni documento.
            if (array_key_exists('imponibile', $gruppo) && is_numeric($gruppo['imponibile'])) {
                $imponibileDichiaratoPerGruppo[$k] = ($imponibileDichiaratoPerGruppo[$k] ?? 0)
                    + (int) round($gruppo['imponibile'] * 100);
            } else {
                $gruppiSenzaImponibile[$k] = true;
            }
        }

        // Un gruppo in cui anche un solo blocco tace l'imponibile non ha un metro attendibile.
        foreach (array_keys($gruppiSenzaImponibile) as $k) {
            unset($imponibileDichiaratoPerGruppo[$k]);
        }

        $pesiPerGruppo = [];
        foreach ($data['righe'] as $i => $riga) {
            $k = $chiave($riga['aliquota_iva'] ?? 0, $riga['natura'] ?? null);
            if (! array_key_exists($k, $impostaPerGruppo)) {
                continue;   // guardia 3: riga di un gruppo che il documento non dichiara
            }
            $pesiPerGruppo[$k][$i] = (int) round(($riga['importo_imponibile'] ?? 0) * 100);
        }

        $distribuita = [];
        foreach ($pesiPerGruppo as $k => $pesi) {
            // guardia 1: le righe a peso zero restano a zero e non entrano nel riparto.
            //
            // ⚠️ **È difensiva, non necessaria — e va detto invece di lasciar credere il
            // contrario.** La Fase 1-bis della beta.19 ha misurato che toglierla non cambia
            // nessun risultato: su 400.000 casi generati con una riga a peso zero e pesi misti,
            // quella riga non riceve mai un centesimo nemmeno senza guardia, perché ha resto 0 e
            // i resti positivi vincono sempre la compensazione. I due test che la nominavano
            // passavano identici senza di essa.
            //
            // Resta perché il costo di sbagliarsi è asimmetrico: una riga a lordo zero non
            // produce scrittura contabile, e un centesimo di IVA sopra la produrrebbe **senza
            // capitolo**, finendo nel ramo «impossibile allocare la riga» — 500 con rollback,
            // fattura persa. Una guardia che non serve costa nulla; quel ramo costa una fattura.
            $conPeso = array_filter($pesi, static fn (int $w): bool => $w !== 0);
            foreach (array_diff_key($pesi, $conPeso) as $i => $_) {
                $distribuita[$i] = 0;
            }
            if ($conPeso === []) {
                continue;
            }

            $somma = array_sum($conPeso);
            if ($somma === 0) {
                continue;   // guardia 2: nessuna proporzione possibile, si torna al calcolo di riga
            }

            // ⚠️ **Guardia 4 — l'imposta dichiarata descrive un imponibile dichiarato.**
            // «Imponibile 45,74, imposta 10,06» e' una frase sola: prenderne la seconda meta'
            // senza controllare la prima significa applicare a righe qualsiasi un numero che
            // descriveva righe precise. Finche' le righe del gruppo sommano all'imponibile che
            // il gruppo dichiara, l'imposta del documento vale; appena divergono, QUEL gruppo —
            // e solo quello — torna al calcolo per riga, che e' il comportamento della beta.18.
            //
            // Fa funzionare il gesto legittimo piu' comune: spezzare una riga da 44,35 in due da
            // 20,00 e 24,35 per addebitarle a capitoli diversi non cambia la somma del gruppo,
            // quindi non costa il centesimo. E fa cedere quello che deve cedere: cambiare un
            // importo, aggiungere o togliere una riga.
            //
            // ⚠️ Il confronto e' sull'imponibile del gruppo, non sul totale del documento: toccare
            // una riga al 22 % non deve disturbare il gruppo a 0 %/N2.2.
            $dichiarato = $imponibileDichiaratoPerGruppo[$k] ?? null;
            if ($dichiarato !== null && array_sum($pesi) !== $dichiarato) {
                foreach ($pesi as $i => $_) {
                    unset($distribuita[$i]);   // anche le righe a peso zero tornano al calcolo di riga
                }

                continue;
            }

            $normalizzati = array_map(static fn (int $w): float => $w / $somma, $conPeso);
            foreach (MoneyHelper::distribuisciPesiNormalizzati($normalizzati, $impostaPerGruppo[$k]) as $i => $iva) {
                $distribuita[$i] = $iva;
            }
        }

        return $distribuita;
    }
}
