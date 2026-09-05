<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\StatoPagamentoFattura;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Duplicati\CollisioneUnicaFattura;
use App\Services\Gestionale\FatturaPassivaService;
use App\Traits\HandleFlashMessages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class StornoFatturaController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(Request $request, Condominio $condominio, FatturaPassiva $fattura, FatturaPassivaService $service)
    {
        if ($fattura->dati_extra['is_stornata'] ?? false) {
            return back()->withErrors(['storno_vietato' => 'Questa fattura è già stata stornata in precedenza.']);
        }

        if ($fattura->netto_a_pagare < 0) {
            return back()->withErrors(['storno_vietato' => 'Non puoi stornare una Nota di Credito.']);
        }

        // Una fattura con pagamenti vivi non può essere annullata da una sola nota di
        // credito: il denaro è già uscito dalla cassa e quel movimento va stornato per
        // primo, altrimenti restano un'uscita di cassa senza debito che la giustifichi e
        // — dopo un'eventuale eliminazione della NC — una fattura "aperta" con pagamenti
        // ancora allocati.
        // withErrors e non flash: in una visita Inertia il flash impostato da back()
        // non arriva a schermo, e l'operazione veniva rifiutata in silenzio. Il canale
        // degli errori di validazione è quello che il frontend riceve sempre.
        if ($fattura->stato_pagamento !== StatoPagamentoFattura::APERTA) {
            return back()->withErrors([
                'storno_vietato' => 'La fattura ha pagamenti registrati. '
                    .'Storna prima il pagamento dalla sezione Pagamenti fornitori, poi la fattura.',
            ]);
        }

        // Beta.19: una copertura CONFERMATA ha un giroconto vivo nel giornale — il
        // fondo è già stato decurtato per questa fattura. Stornare la fattura
        // lasciando in piedi il giroconto consumerebbe il fondo per un debito che
        // non esiste più. Prima si storna il giroconto (la copertura torna in
        // attesa), poi la fattura.
        if ($fattura->coperture()->where('tipo_copertura', 'fondo_riserva')->where('stato', 'confermata')->exists()) {
            return back()->withErrors([
                'storno_vietato' => 'La copertura dal fondo è già stata confermata con un giroconto. '
                    .'Storna prima il giroconto di conferma dalla pagina Giroconti, poi la fattura.',
            ]);
        }

        $gestioneId = null;
        $primaScrittura = $fattura->scritture->first();
        if ($primaScrittura) {
            $gestioneId = $primaScrittura->gestione_id;
        } else {
            $gestioneId = DB::table('gestioni')
                ->where('condominio_id', $condominio->id)
                ->where('attiva', true)
                ->value('id');
        }

        // --- AUTOGENERAZIONE SICURA PROTOCOLLO (Es. NC-2026-0001) ---
        $annoInCorso = date('Y');
        $ultimoProtocollo = DB::table('scritture_contabili')
            ->where('condominio_id', $condominio->id)
            ->where('numero_protocollo', 'like', "NC-{$annoInCorso}-%")
            ->orderBy('id', 'desc')
            ->value('numero_protocollo');

        if ($ultimoProtocollo && preg_match('/-(\d+)$/', $ultimoProtocollo, $matches)) {
            $nextNum = str_pad((int)$matches[1] + 1, 4, '0', STR_PAD_LEFT);
            $protocolloNC = "NC-{$annoInCorso}-{$nextNum}";
        } else {
            $protocolloNC = "NC-{$annoInCorso}-0001";
        }

        try {
            DB::beginTransaction();

            // FIX 1: LO SVUOTA-CESTINO
            // Eliminiamo fisicamente le vecchie scritture "soft-deleted" che bloccano la chiave univoca
            ScritturaContabile::onlyTrashed()
                ->where('condominio_id', $condominio->id)
                ->forceDelete();

            $stornoData = [
                'fornitore_id'               => $fattura->fornitore_id,
                'esercizio_id'               => $fattura->esercizio_id,
                'conto_corrente_id'          => $fattura->conto_corrente_id,
                'tipo_documento'             => 'nota_credito',
                'numero_documento'           => 'STORNO-' . ($fattura->numero_documento ?? $fattura->id),
                'numero_protocollo'          => $protocolloNC, 
                'data_documento'             => now()->format('Y-m-d'),
                'data_scadenza'              => now()->format('Y-m-d'),
                'importo_imponibile'         => abs($fattura->importo_imponibile / 100),
                'importo_iva'                => abs($fattura->importo_iva / 100),
                'importo_ritenuta'           => abs($fattura->importo_ritenuta / 100),
                'totale_documento'           => abs($fattura->totale_documento / 100),
                'netto_a_pagare'             => abs($fattura->netto_a_pagare / 100),
                'is_pregresso'               => $fattura->is_pregresso,
                // ⚠️ **Si leggono le colonne vere, non due attributi che non esistono**
                // (Fase 1-bis della beta.18, rilievo 4). `imponibile_pregresso` e
                // `aliquota_iva_pregressa` venivano scritte da `registraFattura()` ma non sono
                // colonne di `fatture_passive` (29 colonne, nessuna migrazione le crea) e il
                // modello ha `$guarded = ['id']`: Eloquent le scartava in silenzio, quindi
                // rileggerle dava **sempre null**. Lo storno di una fattura pregressa nasceva
                // così a zero: la fattura veniva marcata stornata e il giornale non veniva
                // toccato — il debito restava a bilancio senza che niente lo segnalasse.
                'imponibile_pregresso'       => abs((int) $fattura->importo_imponibile) / 100,
                // L'aliquota si ricava dal rapporto fra i due importi salvati: è il verso
                // inverso del calcolo che l'ha prodotta (`iva = imponibile * aliquota / 100`),
                // e torna al centesimo perché entrambi sono colonne reali.
                'aliquota_iva_pregressa'     => (int) $fattura->importo_imponibile !== 0
                    ? round(abs((int) $fattura->importo_iva) / abs((int) $fattura->importo_imponibile) * 100, 2)
                    : 0,
                'modalita_pagamento'         => $fattura->modalita_pagamento,
                'gestione_id'                => $gestioneId,
                'stato_approvazione'         => 'approvata',
                // Design §8 punto 2: forzare false qui lasciava un DARE fantasma su
                // 2201 e un AVERE residuo su 2202 quando l'originale aveva ritenuta.
                // Propaghiamo il fatto dall'originale: se aveva ritenuta, lo storno
                // deve stornarla assieme al resto.
                'applica_ritenuta'           => $fattura->importo_ritenuta > 0,
                // FIX (revisione avversariale): FISSA l'importo dell'originale invece
                // di farlo ricalcolare da RitenutaService sullo stato ATTUALE del
                // fornitore. Se l'anagrafica del fornitore cambia fra la
                // registrazione e lo storno (es. diventa forfetario, o cambia
                // aliquota), ricalcolare produrrebbe un importo diverso da quello
                // davvero registrato — riaprendo lo stesso residuo fantasma su
                // 2201/2202 che questo storno deve chiudere. Lo storno annulla
                // l'importo REALE, non un importo "ricalcolato secondo le regole di oggi".
                'ritenuta_override' => $fattura->importo_ritenuta > 0 ? [
                    'importo_cents' => abs($fattura->importo_ritenuta),
                    'aliquota' => $fattura->dati_extra['fiscal']['ritenuta_details']['aliquota'] ?? null,
                    'codice_tributo' => $fattura->dati_extra['fiscal']['ritenuta_details']['codice_tributo'] ?? null,
                    'imponibile_calcolo' => $fattura->dati_extra['fiscal']['ritenuta_details']['imponibile_calcolo'] ?? null,
                ] : null,

                // ⚠️ **Niente `abs()` qui: lo storno deve essere lo specchio dell'originale,
                // non la somma dei suoi valori assoluti** (Fase 1-bis della beta.18, rilievo 1).
                // Una fattura ordinaria può contenere una riga negativa — lo storno «Oneri di
                // sistema» che ogni bolletta gas porta dentro il documento — e la beta.18 la
                // registra correttamente. Prendendo il valore assoluto riga per riga, la nota
                // di credito diventava **più grande** della fattura che deve annullare: su una
                // bolletta da € 109,80 (riga +€ 100,00 e storno −€ 10,00) nasceva una NC da
                // € 134,20: restavano € 24,40 di credito verso il fornitore a cui non
                // corrisponde nessun denaro, e un capitolo con spesa NEGATIVA che entra nel
                // rendiconto e si ripartisce ai condòmini. Lo scarto era esattamente 2 × il
                // lordo della riga negativa: la stessa firma aritmetica del difetto corretto
                // nel servizio, migrata qui.
                //
                // `DoubleEntryValidator` non poteva vederlo: le due scritture quadrano ciascuna
                // per sé, lo sbilancio è **fra** i due documenti.
                //
                // Il segno lo mette il moltiplicatore −1 del servizio, che riceve queste righe
                // come le riceverebbe da un modulo: naturali.
                'righe' => $fattura->righe->map(fn($r) => [
                    'descrizione'        => '[STORNO] ' . $r->descrizione,
                    'importo_imponibile' => (float) $r->importo_imponibile / 100,
                    'aliquota_iva'       => (float) $r->aliquota_iva,
                    'importo_iva'        => (float) $r->importo_iva / 100,
                    'conto_id'           => $r->conto_id,
                    'immobile_id'        => $r->immobile_id,
                    // Propagati dall'originale per far tornare l'importo di ritenuta
                    // storncato esattamente identico a quello versato in origine.
                    'concorre_base_ritenuta' => $r->concorre_base_ritenuta,
                    'natura_riga_ritenuta'   => $r->natura_riga_ritenuta?->value,
                ])->toArray(),
                
                // --- INIZIO FIX: RIMBORSO FONDO E COPERTURE ---
                // Passiamo le stesse coperture. Il Service, essendo una Nota di Credito, 
                // invertirà il DARE/AVERE rimborsando automaticamente il Fondo Riserva!
                'coperture' => $fattura->coperture->map(fn($c) => [
                    'tipo_copertura' => $c->tipo_copertura,
                    'importo'        => abs($c->importo / 100),
                    'fonte_id'       => $c->saldo_id ?? $c->conto_id ?? $c->fondo_id
                ])->toArray(),
                // --- FINE FIX ---

                // --- INIZIO FIX: AUDIT TRAIL ---
                'dati_extra' => [
                    'nota_storno' => "Storno automatico a compensazione della fattura ID: {$fattura->id}",
                    'audit_trail' => [
                        'evento' => 'storno_fattura',
                        'fattura_originale_id' => $fattura->id,
                        'utente_id' => Auth::id(),
                        'data' => now()->toIso8601String(),
                        'azioni_generate' => ['storno_scritture', 'ricalcolo_budget', 'ripristino_coperture']
                    ]
                ]
            ];

            // GENERIAMO IL CLONE TRAMITE IL SERVICE
            $notaCredito = $service->registraFattura($stornoData, $condominio->id);

            // FIX 2: IL POST-PROCESSING CHIRURGICO
            // Il Service forza 'fattura_acquisto' e 'FTP-'. Noi li correggiamo all'istante.
            $scritturaNC = $notaCredito->scritture()->first();
            if ($scritturaNC) {
                $scritturaNC->update([
                    'tipo_movimento'    => 'storno_fattura', // Valore che hai aggiunto nella migration!
                    'numero_protocollo' => $protocolloNC,
                    'causale'           => 'Storno Fattura n. ' . ($fattura->numero_documento ?? $fattura->id)
                ]);
            }
            
            $notaCredito->update(['numero_protocollo' => $protocolloNC]);

            // FIX 3 (revisione avversariale): RIENTRO DEL BUDGET "RATA INTEGRATIVA"
            // Se la fattura originale aveva alzato conto->importo automaticamente
            // (beta.27, strategia_rientro='rata_integrativa' — vedi FatturaPassivaService::
            // registraFattura(), che registra ogni bump in dati_extra.rata_integrativa_bump
            // con l'importo PRECEDENTE), lo storno lo ripristina esattamente. Senza questo,
            // un budget mai ratificato in assemblea per l'eccedenza restava permanentemente
            // "approvato": una fattura futura fino al vecchio importo gonfiato non avrebbe
            // più generato alcun allarme di sforo, aggirando in silenzio la garanzia di
            // ratifica (Art. 1135 c.c.) che l'intero flusso ModalOverrideBudget protegge.
            // Ripristino ESATTO, non ricalcolo dalle righe_fattura residue: dopo uno storno
            // completo quella somma tornerebbe a 0, cancellando un budget deliberato
            // legittimamente più alto del semplice speso reale (es. €5.000 deliberati anche
            // se questa era l'unica fattura mai registrata su quella voce).
            foreach (($fattura->dati_extra['rata_integrativa_bump'] ?? []) as $bump) {
                $contoDaRipristinare = Conto::lockForUpdate()->find($bump['conto_id'] ?? null);
                if ($contoDaRipristinare) {
                    $contoDaRipristinare->importo = (int) $bump['importo_precedente_cents'];
                    $contoDaRipristinare->save();
                }
            }

            // CONGELAMENTO FATTURA ORIGINALE
            $datiExtra = $fattura->dati_extra ?? [];
            $datiExtra['is_stornata'] = true;
            $datiExtra['stornata_da_id'] = $notaCredito->id;
            
            $fattura->update([
                'dati_extra'      => $datiExtra,
                'stato_pagamento' => 'stornata'
            ]);

            DB::commit();
            return back()->with($this->flashSuccess("Storno eseguito! Nota di Credito n. {$protocolloNC} generata."));

        } catch (UniqueConstraintViolationException $e) {
            DB::rollBack();

            // ⚠️ **Preesistente, non introdotto dalla beta.13** — questo storno collide con
            // l'indice unico su `fatture_passive` da quando l'indice esiste (24/02/2026):
            // stornando lo stesso giorno due fatture dello stesso fornitore con lo stesso
            // numero, la nota di credito generata (`STORNO-<numero>` più la data odierna)
            // collide con l'altro storno. Prima di questa riga il messaggio grezzo di
            // `$e->getMessage()` arrivava a video con host, porta e nome del database MySQL —
            // trovato dalla revisione avversariale della beta.13. La stringa tecnica resta nel
            // log, non più in faccia all'amministratore.
            Log::error("Errore Storno Fattura ID {$fattura->id} (indice unico): " . $e->getMessage());

            if (! CollisioneUnicaFattura::rilevata($e)) {
                return back()->with($this->flashError('Impossibile stornare il documento: errore tecnico durante il salvataggio.'));
            }

            return back()->with($this->flashError(
                'Esiste già uno storno di questo fornitore con lo stesso numero e la stessa data. '
                .'Riprova, o contatta l\'assistenza se il problema persiste.'
            ));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Errore Storno Fattura ID {$fattura->id}: " . $e->getMessage());
            return back()->with($this->flashError("Impossibile stornare il documento: " . $e->getMessage()));
        }
    }
}