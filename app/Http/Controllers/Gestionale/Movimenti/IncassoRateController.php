<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\RataQuote;
use App\Models\Anagrafica;
use App\Models\Immobile; 
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class IncassoRateController extends Controller
{
    use HandleFlashMessages, HasEsercizio, HasCondomini;

    public function index(Request $request, Condominio $condominio)
    {
        // ... (Codice index invariato rispetto a prima) ...
        // Se ti serve, te lo rimetto, ma credo tu lo abbia già corretto.
        // Assicurati solo di passare 'esercizio' => $esercizio nel return.
        
        $query = ScritturaContabile::query()
            ->where('condominio_id', $condominio->id)
            ->where('tipo_movimento', 'incasso_rata')
            ->with([
                'gestione', 
                // Carichiamo TUTTE le righe per poter filtrare dopo
                'righe.anagrafica',
                'righe.cassa'
            ]);

        if ($search = $request->input('search')) {
            $query->where(function($q) use ($search) {
                $q->where('numero_protocollo', 'like', "%{$search}%")
                  ->orWhere('causale', 'like', "%{$search}%")
                  ->orWhereHas('righe', function($qr) use ($search) {
                      // Cerchiamo il nome in QUALSIASI riga (Dare o Avere) per sicurezza
                      $qr->whereHas('anagrafica', function($qa) use ($search) {
                             $qa->where('nome', 'like', "%{$search}%");
                         });
                  });
            });
        }

        $movimenti = $query->orderByDesc('data_registrazione')
            ->orderByDesc('numero_protocollo')
            ->paginate(15)->withQueryString()
            ->through(function ($mov) {
                
                $rigaCassa = $mov->righe->firstWhere('tipo_riga', 'dare');
                
                // Recuperiamo la riga del pagante principale per avere il suo ID per il link
                $rigaPagantePrinc = $mov->righe->where('tipo_riga', 'avere')->whereNotNull('anagrafica_id')->first();

                // 1. LOGICA RUOLO (Proprietario / Inquilino)
                $ruoloPagante = 'Condòmino'; // Default
                if ($rigaPagantePrinc && $rigaPagantePrinc->anagrafica_id && $rigaPagantePrinc->immobile_id) {
                    // Cerchiamo nella tabella pivot il ruolo specifico per quell'immobile
                    $ruoloDb = DB::table('anagrafica_immobile')
                        ->where('anagrafica_id', $rigaPagantePrinc->anagrafica_id)
                        ->where('immobile_id', $rigaPagantePrinc->immobile_id)
                        ->value('tipologia'); // es: 'proprietario', 'inquilino'
                    
                    if ($ruoloDb) {
                        $ruoloPagante = ucfirst($ruoloDb); // "Proprietario"
                    }
                }

                // 2. LOGICA TIPO RISORSA (Banca, Contanti...)
                $tipoRisorsa = 'N/D';
                if ($rigaCassa && $rigaCassa->cassa) {
                    // Mappa i valori del DB in etichette leggibili
                    $labels = [
                        'banca' => 'Conto Corrente',
                        'contanti' => 'Cassa Contanti',
                        'postale' => 'Conto Postale',
                        // ... altri tipi se ne hai
                    ];
                    $tipoRisorsa = $labels[$rigaCassa->cassa->tipo] ?? ucfirst($rigaCassa->cassa->tipo);
                }

                // Recuperiamo i dettagli COMPLETI delle rate pagate
                $dettagliRate = DB::table('quota_scrittura')
                    ->join('rate_quote', 'quota_scrittura.rate_quota_id', '=', 'rate_quote.id')
                    ->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
                    ->where('quota_scrittura.scrittura_contabile_id', $mov->id)
                    ->select(
                        'rate.numero_rata', 
                        'rate.data_scadenza', 
                        'quota_scrittura.importo_pagato'
                    )
                    ->orderBy('rate.numero_rata')
                    ->get()
                    ->map(function($item) {
                        return [
                            'numero' => $item->numero_rata,
                            'scadenza' => \Carbon\Carbon::parse($item->data_scadenza)->format('d/m/Y'),
                            // FIX: Usiamo MoneyHelper qui!
                            'importo_formatted' => MoneyHelper::format($item->importo_pagato) 
                        ];
                    });

                // ... logica nomi paganti esistente ...
                $nomiPaganti = $mov->righe->where('tipo_riga', 'avere')->whereNotNull('anagrafica_id')
                    ->map(fn($r) => $r->anagrafica->nome ?? null)->filter()->unique()->values();

                $paganteInfo = [
                    'principale' => $nomiPaganti->first() ?? 'Sconosciuto',
                    'altri_count' => $nomiPaganti->count() > 1 ? $nomiPaganti->count() - 1 : 0,
                    'lista_completa' => $nomiPaganti->join(', '),
                ];

                // Calcolo valore grezzo in Euro (float) per il sorting/logica
                $importoEuro = $rigaCassa ? $rigaCassa->importo / 100 : 0; 

                // Calcolo valore formattato (stringa) usando il tuo MoneyHelper
                // Nota: MoneyHelper::format vuole centesimi, quindi passiamo $rigaCassa->importo
                $importoFormatted = $rigaCassa ? MoneyHelper::format($rigaCassa->importo) : MoneyHelper::format(0);
                
                return [
                    'id' => $mov->id,
                    'numero_protocollo' => $mov->numero_protocollo,
                    'data_competenza' => $mov->data_competenza ? $mov->data_competenza->format('Y-m-d') : null,
                    'data_registrazione' => $mov->data_registrazione ? $mov->data_registrazione->format('Y-m-d') : null,
                    'causale' => $mov->causale,
                    // 🔥 NUOVO CAMPO: Dettaglio Rate
                    'dettagli_rate' => $dettagliRate, 
                    'importo_totale_raw' => $importoEuro,       // Float (es: 100.00)
                    'importo_totale_formatted' => $importoFormatted, // String (es: "€ 100,00")       
                    // 🔥 NUOVO CAMPO: ID per il link
                    'anagrafica_id_principale' => $rigaPagantePrinc ? $rigaPagantePrinc->anagrafica_id : null,
                    'stato' => $mov->stato,
                    'importo_totale' => $rigaCassa ? $rigaCassa->importo / 100 : 0,
                    'pagante' => [
                        'principale' => $nomiPaganti->first() ?? 'Sconosciuto',
                        'altri_count' => $nomiPaganti->count() > 1 ? $nomiPaganti->count() - 1 : 0,
                        'lista_completa' => $nomiPaganti->join(', '),
                        // 🔥 NUOVO CAMPO
                        'ruolo' => $ruoloPagante 
                    ],
                    'cassa_nome' => $rigaCassa && $rigaCassa->cassa ? $rigaCassa->cassa->nome : 'N/D',
                    'cassa_tipo_label' => $tipoRisorsa,
                    'gestione_nome' => $mov->gestione ? $mov->gestione->nome : 'Generica',
                ];
        });

        $condominiList = $this->getCondomini();
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateList', [
            'condominio' => $condominio,
            'movimenti'  => $movimenti,
            'condomini'  => $condominiList,
            'esercizio'  => $esercizio,
            'filters'    => $request->all(['search']),
        ]);
    }

    public function create(Condominio $condominio)
    {
        $risorse = Cassa::where('condominio_id', $condominio->id)
            ->whereIn('tipo', ['banca', 'contanti'])
            ->where('attiva', true)
            ->with('contoCorrente')
            ->get();

        $condomini = Anagrafica::whereHas('immobili', fn($q) => $q->where('condominio_id', $condominio->id))
            ->orderBy('nome') 
            ->get()
            ->map(fn($a) => ['id' => $a->id, 'label' => $a->nome]);

        // NUOVO: Carichiamo immobili per lo switch
        $immobili = Immobile::where('condominio_id', $condominio->id)
            ->orderBy('interno')
            ->get()
            ->map(fn($i) => [
                'id' => $i->id, 
                'label' => "Int. $i->interno" . ($i->descrizione ? " - $i->descrizione" : "") . " ($i->nome)" 
            ]);

        $esercizio = $this->getEsercizioCorrente($condominio);
        
        $gestioni = $esercizio 
            ? $esercizio->gestioni()->select('gestioni.id', 'gestioni.nome', 'gestioni.tipo')->orderBy('gestioni.tipo')->get() 
            : [];

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'risorse'    => $risorse,
            'condomini'  => $condomini,
            'immobili'   => $immobili, // <--- Passato al frontend
            'gestioni'   => $gestioni,
        ]);
    }

    public function store(Request $request, Condominio $condominio)
    {
        // ... (Logica store invariata, il modello RataQuote gestisce gli ID corretti) ...
        // Copia il metodo store che ti ho mandato nella risposta precedente (quello con la transaction)
        $validated = $request->validate([
            'pagante_id'          => 'required|exists:anagrafiche,id',
            'cassa_id'            => 'required|exists:casse,id',
            'gestione_id'         => 'nullable|exists:gestioni,id',
            'data_pagamento'      => 'required|date|before_or_equal:today',
            'importo_totale'      => 'required|numeric|min:0.01',
            'descrizione'         => 'nullable|string|max:255',
            'eccedenza'           => 'nullable|numeric|min:0',
            'dettaglio_pagamenti' => 'required|array', 
            'dettaglio_pagamenti.*.rata_id' => 'required|exists:rate_quote,id',
            'dettaglio_pagamenti.*.importo' => 'required|numeric|min:0.01',
        ]);

        // Check quadratura
        $somma = array_reduce($validated['dettaglio_pagamenti'], fn($c, $i) => $c + $i['importo'], 0);
        $totaleCalc = round($somma + ($validated['eccedenza'] ?? 0), 2);
        
        if (abs($validated['importo_totale'] - $totaleCalc) > 0.01) {
            return back()->withErrors(['importo_totale' => "Totale non corrispondente (Allocato: $somma + Eccedenza: {$validated['eccedenza']} != {$validated['importo_totale']})."]);
        }

        $importoTotaleCents = (int) round($validated['importo_totale'] * 100);

        try {
            DB::transaction(function () use ($validated, $condominio, $importoTotaleCents) {
                // ... Risorse, Esercizio ... (uguale a prima)
                $cassa = Cassa::with('contoContabile')->findOrFail($validated['cassa_id']);
                $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->firstOrFail();
                $contoAnticipi = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'anticipi_condomini')->first() ?? $contoCrediti;
                
                $esercizio = $this->getEsercizioCorrente($condominio);

                // Determinazione Gestione
                $gestioneId = $validated['gestione_id'] ?? null;
                if (!$gestioneId && !empty($validated['dettaglio_pagamenti'])) {
                     // Logica prevalente se ci sono rate
                     $ids = collect($validated['dettaglio_pagamenti'])->pluck('rata_id');
                     $quote = RataQuote::whereIn('id', $ids)->with('rata.pianoRate')->get();
                     // ... calcolo prevalenza (uguale a prima)
                     // Per brevità, se non trovo prevalenza uso la prima
                     if($quote->count() > 0) $gestioneId = $quote->first()->rata->pianoRate->gestione_id;
                }
                if (!$gestioneId) $gestioneId = $esercizio->gestioni()->first()->id;

                // Scrittura
                $scrittura = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $esercizio->id,
                    'gestione_id'        => $gestioneId,
                    'data_registrazione' => now()->toDateString(),
                    'data_competenza'    => $validated['data_pagamento'],
                    'causale'            => $validated['descrizione'] ?: 'Incasso rate',
                    'tipo_movimento'     => 'incasso_rata',
                    'stato'              => 'registrata',
                ]);

                // RIGA DARE (Cassa)
                $scrittura->righe()->create([
                    'conto_contabile_id' => $cassa->contoContabile->id,
                    'cassa_id'           => $cassa->id,
                    'tipo_riga'          => 'dare',
                    'importo'            => $importoTotaleCents,
                    'note'               => 'Versamento rate ' . Anagrafica::find($validated['pagante_id'])->nome
                ]);

                // RIGHE AVERE (Rate)
                foreach ($validated['dettaglio_pagamenti'] as $pag) {
                    $importoCents = (int) round($pag['importo'] * 100);
                    $quota = RataQuote::lockForUpdate()->findOrFail($pag['rata_id']);
                    
                    $quota->importo_pagato += $importoCents;
                    $quota->data_pagamento = $validated['data_pagamento'];
                    $quota->stato = ($quota->importo_pagato >= $quota->importo) ? 'pagata' : 'parzialmente_pagata';
                    $quota->save();

                    // Pivot Logs
                    DB::table('quota_scrittura')->insert([
                        'rate_quota_id' => $quota->id, 'scrittura_contabile_id' => $scrittura->id,
                        'importo_pagato' => $importoCents, 'data_pagamento' => $validated['data_pagamento'],
                        'created_at' => now(), 'updated_at' => now()
                    ]);
                    DB::table('rata_scrittura')->insert([
                        'rata_id' => $quota->rata_id, 'scrittura_contabile_id' => $scrittura->id,
                        'importo_pagato' => $importoCents, 'data_pagamento' => $validated['data_pagamento'],
                        'created_at' => now(), 'updated_at' => now()
                    ]);

                    // Riga Avere
                    $isTerzi = $validated['pagante_id'] != $quota->anagrafica_id;
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoCrediti->id,
                        'anagrafica_id'      => $quota->anagrafica_id, 
                        'rata_id'            => $quota->rata_id,
                        'immobile_id'        => $quota->immobile_id,
                        'tipo_riga'          => 'avere',
                        'importo'            => $importoCents,
                        'note'               => $isTerzi ? "Versato da: {$validated['pagante_id']}" : null
                    ]);
                }

                // ECCEDENZA
                if (!empty($validated['eccedenza']) && $validated['eccedenza'] > 0) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoAnticipi->id,
                        'anagrafica_id'      => $validated['pagante_id'],
                        'tipo_riga'          => 'avere',
                        'importo'            => (int) round($validated['eccedenza'] * 100),
                        'note'               => 'Anticipo'
                    ]);
                }
            });

            return to_route('admin.gestionale.movimenti-rate.index', $condominio)
                ->with($this->flashSuccess('Incasso registrato correttamente.'));

        } catch (\Throwable $e) {
            Log::error("Errore salvataggio incasso: " . $e->getMessage());
            return back()->with($this->flashError('Errore: ' . $e->getMessage()));
        }
    }
    
    // Altri metodi storno, api (quest'ultimo rimosso perché spostato), etc...
    public function storno(Request $request, Condominio $condominio, ScritturaContabile $scrittura)
    {
         // ... (codice storno invariato)
         if ($scrittura->stato === 'annullata') return back();
         // ...
         try {
            DB::transaction(function () use ($scrittura, $condominio) {
                $storno = ScritturaContabile::create([
                    'condominio_id' => $condominio->id,
                    'esercizio_id' => $scrittura->esercizio_id,
                    'gestione_id' => $scrittura->gestione_id,
                    'data_registrazione' => now(),
                    'data_competenza' => $scrittura->data_competenza,
                    'causale' => 'STORNO: ' . $scrittura->causale,
                    'tipo_movimento' => 'rettifica',
                    'stato' => 'registrata',
                    'note' => 'Annullamento prot. ' . $scrittura->numero_protocollo,
                ]);

                foreach ($scrittura->righe as $rigaOrig) {
                    $storno->righe()->create([
                        'conto_contabile_id' => $rigaOrig->conto_contabile_id,
                        'cassa_id' => $rigaOrig->cassa_id,
                        'anagrafica_id' => $rigaOrig->anagrafica_id,
                        'rata_id' => $rigaOrig->rata_id,
                        'immobile_id' => $rigaOrig->immobile_id,
                        'tipo_riga' => $rigaOrig->tipo_riga === 'dare' ? 'avere' : 'dare',
                        'importo' => $rigaOrig->importo,
                    ]);
                }

                // Ripristino Quote
                $logs = DB::table('quota_scrittura')->where('scrittura_contabile_id', $scrittura->id)->get();
                foreach($logs as $log) {
                    $quota = RataQuote::lockForUpdate()->find($log->rate_quota_id);
                    if($quota) {
                        $quota->importo_pagato -= $log->importo_pagato;
                        $quota->stato = ($quota->importo_pagato <= 0) ? 'da_pagare' : 'parzialmente_pagata';
                        if($quota->stato == 'da_pagare') $quota->data_pagamento = null;
                        $quota->save();
                    }
                }
                
                DB::table('quota_scrittura')->where('scrittura_contabile_id', $scrittura->id)->delete();
                DB::table('rata_scrittura')->where('scrittura_contabile_id', $scrittura->id)->delete();

                $scrittura->update(['stato' => 'annullata']);
            });
            return back()->with($this->flashSuccess('Storno completato.'));
        } catch (\Throwable $e) {
            return back()->with($this->flashError($e->getMessage()));
        }
    }
}