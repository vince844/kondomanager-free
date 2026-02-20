<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreIncassoRateRequest;
use App\Models\Condominio;
use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Immobile;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\RataQuote; 
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\IncassoRateService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncassoRateController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    public function __construct(
        private IncassoRateService $incassoService
    ) {}

    public function index(Request $request, Condominio $condominio)
    {
        $query = $this->incassoService->getIncassiQuery(
            $condominio,
            $request->input('search')
        );

        $movimenti = $query->paginate(config('pagination.default_per_page'))
            ->withQueryString()
            ->through(fn($mov) => $this->incassoService->formatMovimentoForFrontend($mov));

        $condominiList = Anagrafica::whereHas('immobili', fn($q) => 
            $q->where('condominio_id', $condominio->id)
        )->orderBy('nome')->get();
        
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

    public function store(StoreIncassoRateRequest $request, Condominio $condominio, StoreIncassoRateAction $action) 
    {
        // 1. Esegui l'azione di business (registra soldi)
        $action->execute($request->validated(), $condominio, $this->getEsercizioCorrente($condominio));

        // --- INIZIO AGGIORNAMENTO EVENTI ---

        $paganteId = $request->input('pagante_id');
        
        // Recuperiamo gli ID delle QUOTE (rate_quote) dal form
        $dettaglioPagamenti = $request->input('dettaglio_pagamenti', []);
        $quoteIds = collect($dettaglioPagamenti)->pluck('rata_id')->filter()->toArray();

        if (!empty($quoteIds) && $paganteId) {
            
            // FIX FONDAMENTALE: Convertiamo ID Quote -> ID Rate (Padri)
            // L'evento è legato alla Rata generale, non alla singola quota
            $rataIdsReali = RataQuote::whereIn('id', $quoteIds)
                ->pluck('rata_id')
                ->unique()
                ->toArray();

            // Ora cerchiamo usando gli ID corretti
            $eventiDaAggiornare = Evento::where('meta->type', 'scadenza_rata_condomino')
                ->whereIn('meta->context->rata_id', $rataIdsReali)
                ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $paganteId))
                ->get();

            foreach ($eventiDaAggiornare as $evento) {
                
                $rataId = $evento->meta['context']['rata_id'] ?? null;
                
                // Ricarichiamo la rata dal DB per avere i dati aggiornati
                $rataFresca = Rata::with('rateQuote')->find($rataId);
                
                if ($rataFresca) {
                    // Filtriamo le quote di questo specifico condomino
                    $quoteUtente = $rataFresca->rateQuote->where('anagrafica_id', $paganteId);
                    
                    $totaleDovuto = $quoteUtente->sum('importo');
                    $totalePagato = $quoteUtente->sum('importo_pagato');
                    $restante = $totaleDovuto - $totalePagato;

                    // Aggiorniamo i metadati dell'evento
                    $meta = $evento->meta;
                    $meta['importo_pagato'] = $totalePagato;
                    $meta['importo_restante'] = $restante;

                    // Calcolo dello Stato
                    if ($restante <= 0.01) {
                        $meta['status'] = 'paid';
                    } elseif ($totalePagato > 0.01) {
                        $meta['status'] = 'partial';
                    } else {
                        $meta['status'] = 'pending';
                    }

                    $evento->update(['meta' => $meta]);
                }
            }
        }

        // C. Chiusura Specifica del Task Admin (Solo se arriviamo dalla Inbox)
        $relatedTaskId = $request->input('related_task_id');

        if ($relatedTaskId) {
            $task = Evento::find($relatedTaskId);
            
            if ($task && !$task->is_completed) {
                $task->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);
                
                InboxService::clearAdminCache();
            }
        }

        return to_route('admin.gestionale.movimenti-rate.index', $condominio)
            ->with($this->flashSuccess('Incasso registrato con successo.'));
    }
    
    public function storno(Request $request, Condominio $condominio, ScritturaContabile $scrittura, StornoIncassoRateAction $action) 
    {
        if ($scrittura->stato === 'annullata') {
            return back();
        }

        $action->execute($scrittura, $condominio);

        return back()->with($this->flashSuccess('Storno completato.'));
    }
}