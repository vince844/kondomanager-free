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

    /**
     * Costruttore del controller.
     * Inietta il service responsabile del recupero e della formattazione dei dati per gli incassi.
     *
     * @param IncassoRateService $incassoService
     */
    public function __construct(private IncassoRateService $incassoService) {}

    /**
     * Mostra la lista degli incassi registrati per il condominio corrente.
     * Supporta la ricerca, la paginazione e prepara i dati per i filtri frontend.
     *
     * @param Request $request La richiesta HTTP corrente (contiene eventuali query di ricerca).
     * @param Condominio $condominio Il condominio su cui si sta operando.
     * @return \Inertia\Response Renderizzazione della vista Vue (IncassoRateList).
     */
    public function index(Request $request, Condominio $condominio)
    {
        $query = $this->incassoService->getIncassiQuery(
            $condominio,
            $request->input('search')
        );

        $movimenti = $query->paginate(config('pagination.default_per_page'))
            ->withQueryString()
            ->through(fn($mov) => $this->incassoService->formatMovimentoForFrontend($mov));

        // 1. Recuperiamo i PALAZZI veri per il menu a tendina
        $listaPalazzi = CondominioResource::collection($this->getCondomini())->resolve();
        
        // 2. Recuperiamo le PERSONE (se ti servono per la vista o i filtri) e li chiamiamo 'soggetti'
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

    /**
     * Mostra la schermata per la registrazione di un nuovo incasso.
     * Prepara le dipendenze necessarie ai menu a tendina: risorse finanziarie,
     * soggetti paganti, immobili e gestioni attive.
     *
     * @param Condominio $condominio Il condominio su cui si sta operando.
     * @return \Inertia\Response Renderizzazione della vista Vue (IncassoRateNew).
     */
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

    /**
     * Salva un nuovo incasso nel sistema.
     * Delega la logica contabile (spalmatura waterfall) alla StoreIncassoRateAction.
     * Successivamente, aggiorna lo stato degli eventi ("scontrini digitali" nello scadenziario) 
     * e chiude eventuali task pendenti nella Inbox dell'amministratore.
     *
     * @param StoreIncassoRateRequest $request Dati validati provenienti dal form Vue.
     * @param Condominio $condominio Il condominio corrente.
     * @param StoreIncassoRateAction $action L'azione di business che gestisce il salvataggio contabile.
     * @return \Illuminate\Http\RedirectResponse Reindirizzamento alla lista incassi con messaggio di successo.
     */
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

            // Ora cerchiamo usando gli ID corretti (Query Robusta a prova di JSON)
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
            
            /** @var \App\Models\Evento $task */
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
    
    /**
     * Esegue lo storno (annullamento) di un incasso precedentemente registrato.
     * Ripristina il debito sulle rate e segna la scrittura contabile come annullata.
     *
     * @param Request $request La richiesta HTTP corrente.
     * @param Condominio $condominio Il condominio corrente.
     * @param ScritturaContabile $scrittura Il movimento contabile da stornare.
     * @param StornoIncassoRateAction $action L'azione di business che gestisce lo storno.
     * @return \Illuminate\Http\RedirectResponse Reindirizzamento alla vista precedente con messaggio di successo.
     */
    public function storno(Request $request, Condominio $condominio, ScritturaContabile $scrittura, StornoIncassoRateAction $action) 
    {
        if ($scrittura->stato === 'annullata') {
            return back();
        }

        // 1. PRE-CHECK: Fotografiamo rate e anagrafiche PRIMA di lanciare l'Action
        // Usiamo la TUA relazione 'quotePagate' (Dato che la tua Action lavora con questa e non con le righe)
        $rateIds = $scrittura->quotePagate()->pluck('rata_id')->unique()->toArray();
        $anagraficheIds = $scrittura->quotePagate()->pluck('anagrafica_id')->unique()->toArray();

        // 2. Eseguiamo l'azione contabile (ora può fare tutti i detach() che vuole)
        $action->execute($scrittura, $condominio);

        // 3. Ripristiniamo gli eventi utente allo stato "Da Pagare"
        if (!empty($rateIds) && !empty($anagraficheIds)) {
            
            // Query robusta a prova di JSON
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
                $rataId = $evento->meta['context']['rata_id'] ?? null;
                $paganteId = $evento->anagrafiche->first()->id ?? null;

                $rataFresca = Rata::with('rateQuote')->find($rataId);
                
                if ($rataFresca && $paganteId) {
                    $quoteUtente = $rataFresca->rateQuote->where('anagrafica_id', $paganteId);
                    
                    $totalePagato = $quoteUtente->sum('importo_pagato');
                    $totaleDovuto = $quoteUtente->sum('importo');
                    
                    // 🟢 FIX: Usiamo max(0) per evitare restanti negativi
                    $restante = max(0, $totaleDovuto - $totalePagato);

                    $meta = $evento->meta;
                    $meta['importo_pagato'] = $totalePagato;
                    $meta['importo_restante'] = $restante;
                    
                    // Ricalcolo stato post-storno
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