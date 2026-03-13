<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreIncassoRateRequest;
use App\Http\Resources\Condominio\CondominioResource;
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
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncassoRateController extends Controller
{
    use HandleFlashMessages, HasEsercizio, HasCondomini;

    public function __construct(private IncassoRateService $incassoService) {}

    public function index(Request $request, Condominio $condominio)
    {
        $query = $this->incassoService->getIncassiQuery(
            $condominio,
            $request->input('search')
        );

        $movimenti = $query->paginate(config('pagination.default_per_page'))
            ->withQueryString()
            ->through(fn($mov) => $this->incassoService->formatMovimentoForFrontend($mov));

        $listaPalazzi = CondominioResource::collection($this->getCondomini())->resolve();
        
        $soggettiList = Anagrafica::whereHas('immobili', fn($q) => 
            $q->where('condominio_id', $condominio->id)
        )->orderBy('nome')->get();
        
        $esercizio = $this->getEsercizioCorrente($condominio);

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateList', [
            'condominio' => $condominio,
            'movimenti'  => $movimenti,
            'condomini'  => $listaPalazzi, 
            'soggetti'   => $soggettiList, 
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
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
            ->orderBy('nome')->get(['id', 'nome', 'indirizzo', 'codice_fiscale']);

        $immobili = Immobile::where('condominio_id', $condominio->id)
            ->orderBy('interno')->get(['id', 'interno', 'descrizione', 'nome']);

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
        $action->execute($request->validated(), $condominio, $this->getEsercizioCorrente($condominio));

        // --- AGGIORNAMENTO EVENTI SCADENZIARIO ---
        $paganteId = $request->input('pagante_id');
        $dettaglioPagamenti = $request->input('dettaglio_pagamenti', []);

        // 🟢 FIX: Consideriamo SOLO i pagamenti ordinari (importo > 0)
        $quoteOrdinarie = collect($dettaglioPagamenti)
            ->filter(fn($item) => $item['importo'] > 0)
            ->pluck('rata_id')
            ->filter()
            ->toArray();

        if (!empty($quoteOrdinarie) && $paganteId) {

            // 🟢 FIX: Convertiamo ID Quote -> ID Rate (Padri)
            $rataIdsReali = RataQuote::whereIn('id', $quoteOrdinarie)
                ->pluck('rata_id')
                ->unique()
                ->toArray();

            $eventiDaAggiornare = Evento::where('meta->type', 'scadenza_rata_condomino')
                ->where(function ($q) use ($rataIdsReali) {
                    foreach ($rataIdsReali as $rId) {
                        $q->orWhere('meta->context->rata_id', (int)$rId)
                          ->orWhere('meta->context->rata_id', (string)$rId);
                    }
                })
                ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $paganteId))
                ->get();

            foreach ($eventiDaAggiornare as $evento) {
                
                $rataId = $evento->meta['context']['rata_id'] ?? null;
                $rataFresca = Rata::with('rateQuote')->find($rataId);
                
                if ($rataFresca) {
                    // Escludiamo le quote saldo_iniziale negative (crediti) dal calcolo
                    $quoteUtente = $rataFresca->rateQuote
                        ->where('anagrafica_id', $paganteId)
                        ->where('importo', '>', 0);
                    
                    $totaleDovuto = $quoteUtente->sum('importo');
                    $totalePagato = $quoteUtente->sum('importo_pagato');
                    $restante = $totaleDovuto - $totalePagato;

                    $meta = $evento->meta;
                    $meta['importo_pagato'] = $totalePagato;
                    $meta['importo_restante'] = max(0, $restante);

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

        // Chiusura del Task Admin
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

        // 🟢 FIX: Filtriamo solo le quote con importo positivo
        $rateIds = $scrittura->quotePagate()
            ->where('importo', '>', 0)
            ->pluck('rata_id')
            ->unique()
            ->toArray();

        $anagraficheIds = $scrittura->quotePagate()
            ->where('importo', '>', 0)
            ->pluck('anagrafica_id')
            ->unique()
            ->toArray();

        $action->execute($scrittura, $condominio);

        if (!empty($rateIds) && !empty($anagraficheIds)) {
            
            $eventiDaRipristinare = Evento::where('meta->type', 'scadenza_rata_condomino')
                ->where(function ($q) use ($rateIds) {
                    foreach ($rateIds as $rId) {
                        $q->orWhere('meta->context->rata_id', (int)$rId)
                          ->orWhere('meta->context->rata_id', (string)$rId);
                    }
                })
                ->whereHas('anagrafiche', fn($q) => $q->whereIn('anagrafica_id', $anagraficheIds))
                ->get();

            foreach ($eventiDaRipristinare as $evento) {
                $rataId    = $evento->meta['context']['rata_id'] ?? null;
                $paganteId = $evento->anagrafiche->first()->id ?? null;

                $rataFresca = Rata::with('rateQuote')->find($rataId);
                
                if ($rataFresca && $paganteId) {
                    $quoteUtente = $rataFresca->rateQuote
                        ->where('anagrafica_id', $paganteId)
                        ->where('importo', '>', 0);
                    
                    $totalePagato = $quoteUtente->sum('importo_pagato');
                    $totaleDovuto = $quoteUtente->sum('importo');
                    $restante     = max(0, $totaleDovuto - $totalePagato);

                    $meta = $evento->meta;
                    $meta['importo_pagato']  = $totalePagato;
                    $meta['importo_restante'] = $restante;
                    
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

        return back()->with($this->flashSuccess('Storno completato e scadenziario utenti aggiornato.'));
    }
}