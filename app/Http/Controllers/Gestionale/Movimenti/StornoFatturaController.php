<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\StatoPagamentoFattura;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\FatturaPassivaService;
use App\Traits\HandleFlashMessages;
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
                'imponibile_pregresso'       => abs((float) $fattura->imponibile_pregresso / 100),
                'aliquota_iva_pregressa'     => $fattura->aliquota_iva_pregressa,
                'modalita_pagamento'         => $fattura->modalita_pagamento,
                'gestione_id'                => $gestioneId,
                'stato_approvazione'         => 'approvata',
                'applica_ritenuta'           => false,

                'righe' => $fattura->righe->map(fn($r) => [
                    'descrizione'        => '[STORNO] ' . $r->descrizione,
                    'importo_imponibile' => abs((float) $r->importo_imponibile / 100),
                    'aliquota_iva'       => (float) $r->aliquota_iva,
                    'importo_iva'        => abs((float) $r->importo_iva / 100),
                    'conto_id'           => $r->conto_id,
                    'immobile_id'        => $r->immobile_id
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

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Errore Storno Fattura ID {$fattura->id}: " . $e->getMessage());
            return back()->with($this->flashError("Impossibile stornare il documento: " . $e->getMessage()));
        }
    }
}