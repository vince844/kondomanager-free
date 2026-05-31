<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreIncassoRateRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Immobile;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Rata;
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
     * Inizializza il controller iniettando il servizio per la gestione incassi.
     *
     * @param IncassoRateService $incassoService Servizio contenente le logiche di query e formattazione dei movimenti.
     */
    public function __construct(private IncassoRateService $incassoService) {}

    /**
     * Mostra la lista paginata degli incassi registrati per il condominio selezionato.
     * Fornisce al frontend i dati necessari per popolare la vista, inclusi i filtri di ricerca,
     * la lista dei condòmini e gli esercizi contabili disponibili.
     *
     * @param Request $request L'istanza della richiesta HTTP corrente contenente gli eventuali parametri di ricerca (es. ?search=...).
     * @param Condominio $condominio L'istanza del condominio in cui si sta operando.
     * @return \Inertia\Response Ritorna il componente Vue della lista incassi con le relative properties.
     */
    public function index(Request $request, Condominio $condominio)
    {
        $query = $this->incassoService->getIncassiQuery(
            $condominio,
            $request->input('search'),
            $request->input('stato')
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

        $stats = [
            'totale_incassi' => \App\Models\Gestionale\ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('tipo_movimento', 'incasso_rata')
                ->count(),
            'incassi_mese'   => \App\Models\Gestionale\ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('tipo_movimento', 'incasso_rata')
                ->whereMonth('data_registrazione', now()->month)
                ->whereYear('data_registrazione', now()->year)
                ->count(),
            'stornati'       => \App\Models\Gestionale\ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('tipo_movimento', 'incasso_rata')
                ->where('stato', 'stornato')
                ->count(),
        ];

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateList', [
            'condominio' => $condominio,
            'movimenti'  => $movimenti,
            'condomini'  => $listaPalazzi, 
            'soggetti'   => $soggettiList, 
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'stats'      => $stats,
            'filters'    => $request->all(['search', 'stato']),
        ]);
    }

    /**
     * Genera la schermata e prepara i dati per il form di registrazione di un nuovo incasso.
     * Recupera le entità relazionali attive (casse, anagrafiche, immobili, gestioni dell'esercizio corrente)
     * necessarie per compilare correttamente la Partita Doppia.
     *
     * @param Condominio $condominio L'istanza del condominio in cui si sta operando.
     * @return \Inertia\Response Ritorna il componente Vue per la creazione dell'incasso.
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
     * Elabora, salva e contabilizza la registrazione di un incasso.
     * * Oltre ad affidare la creazione delle righe contabili alla StoreIncassoRateAction, 
     * questo metodo esegue operazioni di sincronizzazione dell'ecosistema:
     * 1. Ricalcola in tempo reale gli importi residui delle rate nello scadenziario dell'utente
     * (aggiornando lo stato in 'paid', 'partial' o 'pending').
     * 2. Intercetta e chiude automaticamente i "Task" di verifica incasso nella Inbox dell'amministratore 
     * se l'incasso è derivato da una segnalazione del condòmino.
     *
     * @param StoreIncassoRateRequest $request Request validata con payload rigoroso per l'operazione contabile.
     * @param Condominio $condominio L'istanza del condominio in cui si sta operando.
     * @param StoreIncassoRateAction $action L'Azione dedicata al salvataggio in Partita Doppia (Cassa vs Crediti).
     * @return \Illuminate\Http\RedirectResponse Reindirizzamento alla lista degli incassi con messaggio flash di successo.
     */
    public function store(StoreIncassoRateRequest $request, Condominio $condominio, StoreIncassoRateAction $action) 
    {
        $action->execute($request->validated(), $condominio, $this->getEsercizioCorrente($condominio));

        // --- AGGIORNAMENTO EVENTI SCADENZIARIO ---
        $paganteId = $request->input('pagante_id');
        $dettaglioPagamenti = $request->input('dettaglio_pagamenti', []);

        // FIX: Consideriamo SOLO i pagamenti ordinari (importo > 0)
        $quoteOrdinarie = collect($dettaglioPagamenti)
            ->filter(fn($item) => $item['importo'] > 0)
            ->pluck('rata_id')
            ->filter()
            ->toArray();

        $rataIdsReali = [];
        if (!empty($quoteOrdinarie)) {
            // FIX: Convertiamo ID Quote -> ID Rate (Padri)
            $rataIdsReali = RataQuote::whereIn('id', $quoteOrdinarie)
                ->pluck('rata_id')
                ->unique()
                ->toArray();
        }

        if (!empty($quoteOrdinarie) && $paganteId) {

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
            /** @var \App\Models\Evento|null $task */
            $task = Evento::find($relatedTaskId);

            if ($task && !$task->is_completed) {
                $task->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);
            }
        }

        // -------------------------------------------------------------
        // 2. SMART TASK KILLER: Uccisione Globale (Verifica incassi rata)
        // -------------------------------------------------------------
        if (!empty($quoteOrdinarie)) {
            // Prendiamo le rate padri coinvolte in questo pagamento
            $ratePadri = Rata::whereIn('id', $rataIdsReali)->with('rateQuote')->get();

            foreach ($ratePadri as $rataPadre) {
                // Calcoliamo quanto è stato pagato in totale per QUESTA rata in TUTTO il condominio
                $totaleDovutoCondominio = $rataPadre->rateQuote->where('importo', '>', 0)->sum('importo');
                $totalePagatoCondominio = $rataPadre->rateQuote->where('importo', '>', 0)->sum('importo_pagato');
                
                $residuoCondominio = $totaleDovutoCondominio - $totalePagatoCondominio;

                // Se l'intera rata del condominio è stata saldata (tolleranza 1 centesimo)
                if ($residuoCondominio <= 0.01) {
                    
                    // Cerchiamo l'evento globale "Verifica incassi" per questa rata
                    $rataPadreId = (int) $rataPadre->id;
                    
                    Evento::where('meta->type', 'controllo_incassi')
                        ->where(function($q) use ($rataPadreId) {
                            $q->where('meta->context->rata_id', $rataPadreId)
                              ->orWhere('meta->context->rata_id', (string) $rataPadreId);
                        })
                        ->where('is_completed', false)
                        ->update([
                            'is_completed' => true,
                            'completed_at' => now(),
                        ]);
                }
            }
        }

        // Svuotiamo la cache della inbox dell'admin una sola volta alla fine
        InboxService::clearAdminCache();

        return to_route('admin.gestionale.movimenti-rate.index', $condominio)
            ->with($this->flashSuccess('Incasso registrato con successo.'));
    }
    
}