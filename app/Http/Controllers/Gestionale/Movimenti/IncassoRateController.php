<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\RataQuote; // Usa il nome corretto del modello
use App\Models\Anagrafica;
use App\Models\Immobile;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio; // Rimosso HasCondomini se non usato esplicitamente
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class IncassoRateController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    public function index(Request $request, Condominio $condominio)
    {
        $query = ScritturaContabile::query()
            ->where('condominio_id', $condominio->id)
            ->where('tipo_movimento', 'incasso_rata')
            ->with(['gestione', 'righe.anagrafica', 'righe.cassa']);

        if ($search = $request->input('search')) {
            $query->where(function($q) use ($search) {
                $q->where('numero_protocollo', 'like', "%{$search}%")
                  ->orWhere('causale', 'like', "%{$search}%")
                  ->orWhereHas('righe', function($qr) use ($search) {
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
                
                // Dettagli Rate usando SOLO quota_scrittura (Architettura corretta)
                // Usiamo una subquery o join per recuperare i dettagli in modo efficiente
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
                            'importo_formatted' => MoneyHelper::format($item->importo_pagato) 
                        ];
                    });

                // ... logica nomi paganti esistente ...
                $nomiPaganti = $mov->righe->where('tipo_riga', 'avere')->whereNotNull('anagrafica_id')
                    ->map(fn($r) => $r->anagrafica->nome ?? null)->filter()->unique()->values();

                // Recuperiamo la riga del pagante principale per avere il suo ID
                 $rigaPagantePrinc = $mov->righe->where('tipo_riga', 'avere')->whereNotNull('anagrafica_id')->first();
                 
                 // Logica Ruolo (copiata dal tuo originale)
                $ruoloPagante = 'Condòmino'; 
                if ($rigaPagantePrinc && $rigaPagantePrinc->anagrafica_id && $rigaPagantePrinc->immobile_id) {
                    $ruoloDb = DB::table('anagrafica_immobile')
                        ->where('anagrafica_id', $rigaPagantePrinc->anagrafica_id)
                        ->where('immobile_id', $rigaPagantePrinc->immobile_id)
                        ->value('tipologia');
                    if ($ruoloDb) $ruoloPagante = ucfirst($ruoloDb);
                }

                // Logica Tipo Risorsa (copiata dal tuo originale)
                $tipoRisorsa = 'N/D';
                if ($rigaCassa && $rigaCassa->cassa) {
                     $labels = ['banca' => 'Conto Corrente', 'contanti' => 'Cassa Contanti', 'postale' => 'Conto Postale'];
                     $tipoRisorsa = $labels[$rigaCassa->cassa->tipo] ?? ucfirst($rigaCassa->cassa->tipo);
                }

                $importoEuro = $rigaCassa ? $rigaCassa->importo / 100 : 0; 
                
                return [
                    'id' => $mov->id,
                    'numero_protocollo' => $mov->numero_protocollo,
                    'data_competenza' => $mov->data_competenza ? $mov->data_competenza->format('Y-m-d') : null, // Mantenuto formato originale
                    'data_registrazione' => $mov->data_registrazione ? $mov->data_registrazione->format('Y-m-d') : null,
                    'causale' => $mov->causale,
                    'dettagli_rate' => $dettagliRate, 
                    'importo_totale_raw' => $importoEuro,
                    'importo_totale_formatted' => MoneyHelper::format($rigaCassa ? $rigaCassa->importo : 0),
                    'stato' => $mov->stato,
                    'pagante' => [
                        'principale' => $nomiPaganti->first() ?? 'Sconosciuto',
                        'altri_count' => max(0, $nomiPaganti->count() - 1),
                        'lista_completa' => $nomiPaganti->join(', '),
                         'ruolo' => $ruoloPagante // Aggiunto
                    ],
                    'cassa_nome' => $rigaCassa && $rigaCassa->cassa ? $rigaCassa->cassa->nome : 'N/D',
                    'cassa_tipo_label' => $tipoRisorsa, // Aggiunto
                    'gestione_nome' => $mov->gestione ? $mov->gestione->nome : 'Generica', // Aggiunto
                     'anagrafica_id_principale' => $rigaPagantePrinc ? $rigaPagantePrinc->anagrafica_id : null, // Aggiunto
                ];
        });

        // Recuperiamo dati per filtri (copiato dal tuo originale)
        $condominiList = Anagrafica::whereHas('immobili', fn($q) => $q->where('condominio_id', $condominio->id))->orderBy('nome')->get();
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateList', [
            'condominio' => $condominio,
            'movimenti'  => $movimenti,
            'condomini'  => $condominiList, // Aggiunto
            'esercizio'  => $esercizio,
            'filters'    => $request->all(['search']),
        ]);
    }

    public function create(Condominio $condominio)
    {
        $risorse = Cassa::where('condominio_id', $condominio->id)
            ->whereIn('tipo', ['banca', 'contanti'])
            ->where('attiva', true)
            ->with('contoCorrente') // Aggiunto eager loading
            ->get();

        $condomini = Anagrafica::whereHas('immobili', fn($q) => $q->where('condominio_id', $condominio->id))
            ->orderBy('nome')->get()->map(fn($a) => ['id' => $a->id, 'label' => $a->nome]);

        $immobili = Immobile::where('condominio_id', $condominio->id)
            ->orderBy('interno')->get()
            ->map(fn($i) => ['id' => $i->id, 'label' => "Int. $i->interno" . ($i->descrizione ? " - $i->descrizione" : "") . " ($i->nome)"]);

        $esercizio = $this->getEsercizioCorrente($condominio);
        
        $gestioni = $esercizio 
            ? $esercizio->gestioni()->select('gestioni.id', 'gestioni.nome', 'gestioni.tipo')->orderBy('gestioni.tipo')->get() 
            : [];

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'risorse'    => $risorse,
            'condomini'  => $condomini,
            'immobili'   => $immobili,
            'gestioni'   => $gestioni,
        ]);
    }

    public function store(Request $request, Condominio $condominio)
    {
        $validated = $request->validate([
            'pagante_id'          => 'required|exists:anagrafiche,id',
            'cassa_id'            => 'required|exists:casse,id',
            'gestione_id'         => 'nullable|exists:gestioni,id',
            'data_pagamento'      => 'required|date|before_or_equal:today', // Mantenuta validazione originale
            'importo_totale'      => 'required|numeric|min:0.01',
            'descrizione'         => 'nullable|string|max:255',
            'eccedenza'           => 'nullable|numeric|min:0',
            'dettaglio_pagamenti' => 'required|array', 
            'dettaglio_pagamenti.*.rata_id' => 'required|exists:rate_quote,id',
            'dettaglio_pagamenti.*.importo' => 'required|numeric|min:0.01',
        ]);

        // Check quadratura (Mantenuto dal tuo originale)
        $somma = array_reduce($validated['dettaglio_pagamenti'], fn($c, $i) => $c + $i['importo'], 0);
        $totaleCalc = round($somma + ($validated['eccedenza'] ?? 0), 2);
        
        if (abs($validated['importo_totale'] - $totaleCalc) > 0.01) {
            return back()->withErrors(['importo_totale' => "Totale non corrispondente (Allocato: $somma + Eccedenza: {$validated['eccedenza']} != {$validated['importo_totale']})."]);
        }

        $importoTotaleCents = (int) round($validated['importo_totale'] * 100);

        try {
            DB::transaction(function () use ($validated, $condominio, $importoTotaleCents) {
                
                $cassa = Cassa::with('contoContabile')->findOrFail($validated['cassa_id']);
                $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->firstOrFail();
                $contoAnticipi = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'anticipi_condomini')->first() ?? $contoCrediti;
                
                $esercizio = $this->getEsercizioCorrente($condominio);

                // Determinazione Gestione (Mantenuta logica originale)
                $gestioneId = $validated['gestione_id'] ?? null;
                if (!$gestioneId && !empty($validated['dettaglio_pagamenti'])) {
                     $ids = collect($validated['dettaglio_pagamenti'])->pluck('rata_id');
                     $quote = RataQuote::whereIn('id', $ids)->with('rata.pianoRate')->get();
                     if($quote->count() > 0) $gestioneId = $quote->first()->rata->pianoRate->gestione_id;
                }
                if (!$gestioneId) $gestioneId = $esercizio->gestioni()->first()->id;

                // 1. Scrittura Contabile
                $scrittura = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $esercizio->id,
                    'gestione_id'        => $gestioneId,
                    'data_registrazione' => now(), // o validated['data_pagamento'] se preferisci che data reg = data pagamento
                    'data_competenza'    => $validated['data_pagamento'],
                    'causale'            => $validated['descrizione'] ?: 'Incasso rate',
                    'tipo_movimento'     => 'incasso_rata',
                    'stato'              => 'registrata',
                ]);

                // 2. RIGA DARE (Cassa)
                $scrittura->righe()->create([
                    'conto_contabile_id' => $cassa->contoContabile->id,
                    'cassa_id'           => $cassa->id,
                    'tipo_riga'          => 'dare',
                    'importo'            => $importoTotaleCents,
                    'note'               => 'Versamento rate ' . Anagrafica::find($validated['pagante_id'])->nome
                ]);

                // 3. RIGHE AVERE (Rate) + Aggiornamento Quote
                foreach ($validated['dettaglio_pagamenti'] as $pag) {
                    $importoCents = (int) round($pag['importo'] * 100);
                    
                    // Lock per sicurezza
                    $quota = RataQuote::lockForUpdate()->findOrFail($pag['rata_id']);
                    
                    // A. Attach alla Pivot (Il cuore del sistema)
                    // Usiamo attach() invece di insert() manuale per sfruttare Eloquent e timestamps
                    $quota->pagamenti()->attach($scrittura->id, [
                        'importo_pagato' => $importoCents,
                        'data_pagamento' => $validated['data_pagamento']
                    ]);
                    
                    // NOTA: Rimosso insert su rata_scrittura (tabella eliminata)

                    // B. Riga Contabile (Per il bilancio)
                    $isTerzi = $validated['pagante_id'] != $quota->anagrafica_id;
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoCrediti->id,
                        'anagrafica_id'      => $quota->anagrafica_id, 
                        'rata_id'            => $quota->rata_id,
                        'immobile_id'        => $quota->immobile_id,
                        'tipo_riga'          => 'avere',
                        'importo'            => $importoCents,
                        'note'               => $isTerzi ? "Versato da: {$validated['pagante_id']}" : "Incasso rata n." . ($quota->rata->numero_rata ?? '')
                    ]);

                    // C. Ricalcolo Stato (Basato sulla pivot appena aggiornata)
                    // Sostituisce l'aggiornamento manuale di importo_pagato e stato
                    $quota->ricalcolaStato();
                }

                // 4. Eccedenza
                if (!empty($validated['eccedenza']) && $validated['eccedenza'] > 0) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoAnticipi->id,
                        'anagrafica_id'      => $validated['pagante_id'],
                        'tipo_riga'          => 'avere',
                        'importo'            => (int) round($validated['eccedenza'] * 100),
                        'note'               => 'Anticipo / Eccedenza'
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
    
    public function storno(Request $request, Condominio $condominio, ScritturaContabile $scrittura)
    {
         if ($scrittura->stato === 'annullata') return back();

         try {
            DB::transaction(function () use ($scrittura, $condominio) {
                // 1. Scrittura di Rettifica
                $storno = ScritturaContabile::create([
                    'condominio_id' => $condominio->id,
                    'esercizio_id' => $scrittura->esercizio_id,
                    'gestione_id' => $scrittura->gestione_id,
                    'data_registrazione' => now(),
                    'data_competenza' => $scrittura->data_competenza,
                    'causale' => 'STORNO: ' . $scrittura->causale,
                    'tipo_movimento' => 'rettifica',
                    'stato' => 'registrata',
                    'note' => 'Annullamento prot. ' . $scrittura->numero_protocollo, // Aggiunto note
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

                // 2. Ricalcolo Quote (Prima stacchiamo, poi ricalcoliamo)
                // Usiamo la relazione inversa definita in ScritturaContabile
                $quoteCoinvolte = $scrittura->quotePagate; 
                
                // Cancelliamo la riga dalla pivot (detach gestisce quota_scrittura)
                $scrittura->quotePagate()->detach();       

                // NOTA: Rimosso delete manuale su rata_scrittura (tabella eliminata)
                // NOTA: Rimosso delete manuale su quota_scrittura (fatto da detach)

                foreach($quoteCoinvolte as $quota) {
                    $quota->ricalcolaStato(); // La quota ora vedrà un pagamento in meno e si correggerà
                }

                $scrittura->update(['stato' => 'annullata']);
            });
            return back()->with($this->flashSuccess('Storno completato.'));
        } catch (\Throwable $e) {
            return back()->with($this->flashError($e->getMessage()));
        }
    }
}