<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\StatoPianoRate;
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoRate\CreatePianoRateRequest;
use App\Http\Requests\Gestionale\PianoRate\PianoRateIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\PianiRate\PianoRateResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\BudgetMovement;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Services\Gestionale\SaldoEsercizioService;
use App\Services\PianoRateCreatorService;
use App\Services\PianoRateQuoteService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PianoRateController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    /**
     * Costruttore del controller.
     * Inietta i servizi necessari per l'estrazione delle quote, la creazione dei piani 
     * e la verifica dei saldi pregressi.
     *
     * @param PianoRateQuoteService $pianoRateQuoteService
     * @param PianoRateCreatorService $pianoRateCreatorService
     * @param SaldoEsercizioService $saldoService
     */
    public function __construct(
        private readonly PianoRateQuoteService $pianoRateQuoteService,
        private readonly PianoRateCreatorService $pianoRateCreatorService,
        private readonly SaldoEsercizioService $saldoService,
    ) {}

    /**
     * Mostra l'elenco dei Piani Rate associati a un condominio e a uno specifico esercizio.
     * Supporta la paginazione e la ricerca per nome del piano rate.
     *
     * @param PianoRateIndexRequest $request Dati della richiesta validati (es. filtri, per_page)
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @return Response Renderizzazione del componente Vue per la lista
     */
    public function index(PianoRateIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();
        
        $pianiRate = PianoRate::with(['gestione'])
            ->where('condominio_id', $condominio->id)
            ->whereHas('gestione.esercizi', fn($q) => $q->where('esercizio_id', $esercizio->id))
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/pianiRate/PianiRateList', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'esercizi' => $esercizi,
            'condomini' => CondominioResource::collection($this->getCondomini()),
            'pianiRate' => PianoRateResource::collection($pianiRate)->resolve(),
            'meta' => [
                'current_page' => $pianiRate->currentPage(),
                'last_page' => $pianiRate->lastPage(),
                'per_page' => $pianiRate->perPage(),
                'total' => $pianiRate->total()
            ],
            'filters' => $request->only(['nome']),
        ]);
    }

    /**
     * Mostra la pagina di creazione per un nuovo Piano Rate.
     * Recupera e prepara tutte le dipendenze necessarie (gestioni attive, saldi pregressi vuoti 
     * e anagrafiche) per alimentare il form frontend.
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @return Response Renderizzazione del componente Vue per il form di creazione
     */
    public function create(Request $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();
        
        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = Gestione::whereHas('esercizi', fn($q) => $q->where('esercizio_id', $esercizio->id))
            ->with(['esercizi' => fn($q) => $q->where('esercizio_id', $esercizio->id)])
            ->get();

        // --- FIX CHIRURGICO: Ascoltiamo la richiesta di Inertia (Reload) ---
        if ($request->has('gestione_id') && $request->gestione_id != '') {

            // Se Inertia sta chiedendo i dati di una gestione specifica, la cerchiamo
            $gestioneSelezionata = Gestione::where('condominio_id', $condominio->id)->findOrFail($request->gestione_id);
            
            // E usiamo il service per farci dire se è bloccata o meno
            $saldoInfo = $this->saldoService->calcolaSaldoApplicabile($gestioneSelezionata);

        } else {
            // Comportamento di default (al primo caricamento della pagina)
            $saldoInfo = [
                'saldo' => 0,
                'has_movimenti' => false,
                'applicabile' => false,
                'motivo' => 'Seleziona una gestione per verificare i saldi.',
                'is_primo_anno' => false
            ];
        }
        // -------------------------------------------------------------------

        return Inertia::render('gestionale/pianiRate/PianiRateNew', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'esercizi' => $esercizi,
            'condomini' => $condomini,
            'gestioni' => $gestioni,
            'saldoInfo' => $saldoInfo,
            'anagraficheDisponibili' => $condominio->anagrafiche()->orderBy('nome')->get(['anagrafiche.id', 'nome']),
        ]);
    }

    /**
     * Salva nel database un nuovo Piano Rate.
     * Questo metodo esegue operazioni complesse all'interno di una transazione:
     * 1. Creazione del record principale.
     * 2. Associazione dei conti/sottoconti (Emissione Globale vs Parziale).
     * 3. Configurazione della ricorrenza delle rate.
     * 4. Applicazione e blocco ("lucchetto") dei saldi pregressi della gestione.
     * 5. (Opzionale) Generazione immediata delle rate (Action).
     *
     * @param CreatePianoRateRequest $request La richiesta validata dal form
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @return RedirectResponse Reindirizzamento al dettaglio del piano rate generato
     */
    public function store(CreatePianoRateRequest $request, Condominio $condominio, Esercizio $esercizio)
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // 1. Validazione Gestione
            $gestione = Gestione::findOrFail($validated['gestione_id']);
            $this->pianoRateCreatorService->verificaGestione($validated['gestione_id']);

            // 2. Analisi Saldi: calcola i saldi limitati a questa specifica gestione
            $saldoInfo = $this->saldoService->calcolaSaldoApplicabile($gestione);
            $haMovimenti = $saldoInfo['has_movimenti'] ?? false;
            
            if (!$haMovimenti && $saldoInfo['saldo'] == 0) {
                $esisteManuale = DB::table('saldi')
                    ->where('gestione_id', $gestione->id)
                    ->where('saldo_iniziale', '!=', 0)
                    ->exists();
                if ($esisteManuale) $haMovimenti = true;
            }

            $applicareSaldi = ($saldoInfo['applicabile'] && $haMovimenti);

            // 3. Creazione Core del Piano
            $pianoRate = $this->pianoRateCreatorService->creaPianoRate($validated, $condominio);

            // --- HELPER ricorsivo: Calcola il vero totale sommando i sottoconti ---
            $calcolaVeroTotale = function ($conto) use (&$calcolaVeroTotale) {
                if ($conto->relationLoaded('sottoconti') && $conto->sottoconti->isNotEmpty()) {
                    return $conto->sottoconti->sum(fn($sub) => $calcolaVeroTotale($sub));
                }
                return $conto->importo ?? 0;
            };

            // 4. Gestione Capitoli e Sync (Emissione parziale o totale)
            $capitoliConfig = $validated['capitoli_config'] ?? [];
            $syncData = [];

            if (!empty($capitoliConfig)) {
                // CASO A: Wizard manuale (Cifre specifiche)
                foreach ($capitoliConfig as $conf) {
                    $importoCents = (isset($conf['importo']) && $conf['importo'] !== '') 
                        ? MoneyHelper::toCents($conf['importo']) 
                        : null;
                    
                    $syncData[$conf['id']] = ['importo' => $importoCents, 'note' => $conf['note'] ?? null];
                }
            } elseif (!empty($validated['capitoli_ids'])) {
                // CASO B: Selezione rapida (Intero importo del capitolo scelto)
                $conti = Conto::with('sottoconti')->findMany($validated['capitoli_ids']);
                foreach ($conti as $c) {
                    $syncData[$c->id] = [
                        'importo' => $calcolaVeroTotale($c), 
                        'note' => 'Selezione rapida (Intero)'
                    ];
                }
            } else {
                // CASO C: Inclusione automatica (Tutto il bilancio rimasto orfano per questa gestione)
                $capitoliOrfani = $gestione->pianoConto->conti()
                    ->whereNull('parent_id')
                    ->whereDoesntHave('pianiRate', fn($q) => $q->where('attivo', true))
                    ->with('sottoconti') 
                    ->get();
                
                foreach ($capitoliOrfani as $c) {
                    $syncData[$c->id] = [
                        'importo' => $calcolaVeroTotale($c), 
                        'note' => 'Inclusione automatica (Tutto il bilancio)'
                    ];
                }
            }
            
            $pianoRate->capitoli()->sync($syncData);
            $pianoRate->load('capitoli');

            // 5. Ricorrenza
            if (!empty($validated['recurrence_enabled'])) {
                $this->pianoRateCreatorService->creaRicorrenza($pianoRate, $validated);
            }

            // 6. Applicazione Saldi (Lock micro e macro)
            if ($applicareSaldi) {
                $this->saldoService->marcaSaldoApplicato($gestione, $saldoInfo['saldo']);
                $gestione->refresh();
                $pianoRate->setRelation('gestione', $gestione);
            }

            // 7. Generazione Rate fisiche tramite Action
            $statistiche = [];
            if (!empty($validated['genera_subito'])) {
                
                // RECUPERO SALDI REALI: Se non ci sono config manuali dal front, 
                // prendiamo tutti i saldi non applicati della gestione dal DB
                $saldiConfig = $validated['saldi_config'] ?? [];
                
                if (empty($saldiConfig) && $applicareSaldi) {
                    $saldiConfig = Saldo::where('gestione_id', $gestione->id)
                        ->where('is_applicato', false) 
                        ->get()
                        ->map(fn($s) => [
                            'saldo_id' => $s->id,
                            'ripartizioni' => [] // Vuoto significa "usa riparto automatico"
                        ])->toArray();
                }

                $statistiche = app(GeneratePianoRateAction::class)->execute(
                    $pianoRate, 
                    $applicareSaldi, 
                    $saldiConfig 
                );
            }

            DB::commit();
            return $this->redirectSuccess($condominio, $esercizio, $pianoRate, $validated, $statistiche);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Errore store piano rate", ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with($this->flashError($e->getMessage()));
        }
    }
    
    /**
     * Mostra i dettagli di un singolo Piano Rate.
     * Carica tutte le relazioni necessarie (rate, quote, anagrafiche, immobili) e calcola
     * la copertura del budget (capitoli orfani) e la disponibilità per lo "sposta spesa".
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @param PianoRate $pianoRate L'entità del piano rate da visualizzare
     * @return Response Renderizzazione del componente Vue di dettaglio
     */
    public function show(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): Response
    {
        $pianoRate->load([
            'rate.rateQuote.anagrafica', 
            'rate.rateQuote.immobile', 
            'gestione.pianoConto', 
            'capitoli.sottoconti',
            'budgetMovements.sourceConto',
            'budgetMovements.destinationConto',
            'budgetMovements.user'
        ]);
        
        // Calcolo voci di bilancio non coperte da questo (o altri) piani rate attivi
        $orfani = [];
        if ($pianoRate->gestione && $pianoRate->gestione->pianoConto) {
            $orfaniRaw = $pianoRate->gestione->pianoConto->conti()
                ->whereNull('parent_id')
                ->whereDoesntHave('pianiRate', fn($q) => $q->where('piani_rate.attivo', true))
                ->where('id', '!=', $pianoRate->capitoli->pluck('id')->toArray()) 
                ->get();
            
            $orfani = $orfaniRaw->map(fn($c) => [
                'id' => $c->id, 
                'nome' => $c->nome, 
                'importo' => $c->importo
            ])->values()->toArray();
        }
        
        $coperturaData = [
            'scoperto_count' => count($orfani), 
            'orfani' => $orfani
        ];
        
        // Estrazione delle sole scadenze (Rate pure) per la timeline
        $ratePure = $pianoRate->rate()
            ->orderBy('numero_rata')
            ->get()
            ->map(fn($rata) => [
                'id' => $rata->id, 
                'numero_rata' => $rata->numero_rata, 
                'is_emessa' => $rata->rateQuote()->whereNotNull('scrittura_contabile_id')->exists(), 
                'totale_rata' => MoneyHelper::fromCents($rata->importo_totale)
            ]);

        // Preparazione dati per la logica "Sposta Spesa" (Movimenti Budget)
        $sources = $pianoRate->capitoli->map(function ($conto) {
            $importoReale = $conto->pivot->importo ?? $conto->importo; 
            return [
                'id' => $conto->id,
                'nome' => $conto->nome,
                'importo_residuo' => $importoReale,
                'formatted_residuo' => number_format($importoReale / 100, 2, ',', '.')
            ];
        });

        $destinations = [];
        $pianoContoId = $pianoRate->gestione->pianoConto?->id;

        if ($pianoContoId) {
            $destinations = Conto::where('piano_conto_id', $pianoContoId)
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn($c) => [
                    'id' => $c->id,
                    'nome' => $c->nome,
                ]);
        } else {
            Log::warning("Sposta Spesa: Nessun Piano Conto trovato per la gestione {$pianoRate->gestione_id}");
        }

        return Inertia::render('gestionale/pianiRate/PianiRateShow', [
            'condominio' => $condominio, 
            'esercizio' => $esercizio, 
            'pianoRate' => new PianoRateResource($pianoRate),
            'ratePure' => $ratePure, 
            'quotePerAnagrafica' => $this->pianoRateQuoteService->quotePerAnagrafica($pianoRate),
            'quotePerImmobile' => $this->pianoRateQuoteService->quotePerImmobile($pianoRate),
            'needsMigration' => false, 
            'copertura' => $coperturaData,
            'sources' => $sources,
            'destinations' => $destinations
        ]);
    }

    /**
     * Aggiorna lo stato di approvazione del Piano Rate.
     * Cambiare lo stato dispatcha l'evento 'PianoRateStatusUpdated', che viene 
     * intercettato dai Listener per aggiungere (se approvato) o rimuovere (se in bozza) 
     * gli eventi nello scadenziario generale.
     *
     * @param Request $request Richiesta contenente il booleano 'approvato'
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @param PianoRate $pianoRate Il piano rate da aggiornare
     * @return RedirectResponse Risposta con messaggio flash di successo
     */
    public function updateStato(Request $request, Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate)
    {
        $validated = $request->validate([ 'approvato' => 'required|boolean' ]);
        
        $vecchioStato = $pianoRate->stato;
        $nuovoStato = $validated['approvato'] ? StatoPianoRate::APPROVATO : StatoPianoRate::BOZZA;
        
        $pianoRate->update(['stato' => $nuovoStato]);
        
        PianoRateStatusUpdated::dispatch(
            $condominio, 
            $esercizio, 
            $pianoRate, 
            Auth::user(), 
            $vecchioStato, 
            $nuovoStato
        );
        
        return back()->with($this->flashSuccess('Stato aggiornato con successo.'));
    }

    /**
     * Endpoint API per il Frontend.
     * Recupera i dettagli analitici dei saldi per una specifica gestione, non ancora bloccati.
     * Identifica il ruolo dell'anagrafica sull'immobile (es. proprietario) per supportare
     * la logica di ripartizione avanzata (Subentri Art. 63) all'interno del form di creazione.
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile
     * @param Gestione $gestione La gestione selezionata nel frontend
     * @return \Illuminate\Http\JsonResponse Un array JSON di Saldi pronti per la modale Vue
     */
    public function fetchSaldiAnalitici(Condominio $condominio, Esercizio $esercizio, Gestione $gestione)
    {
        $saldi = Saldo::where('gestione_id', $gestione->id)
            ->where('is_applicato', false)
            ->with(['anagrafica', 'immobile'])
            ->get()
            ->map(function($s) use ($condominio) {
                $ruolo = null;
                if ($s->anagrafica_id && $s->immobile_id) {
                    $ruolo = DB::table('anagrafica_immobile')
                        ->where('anagrafica_id', $s->anagrafica_id)
                        ->where('immobile_id', $s->immobile_id)
                        ->value('tipologia'); 
                }

                return [
                    'id' => $s->id,
                    'anagrafica_id' => $s->anagrafica_id,
                    'tipo' => $s->anagrafica_id ? 'nominale' : 'solidale',
                    'soggetto_nome' => $s->anagrafica_id ? $s->anagrafica->nome : "Unità " . ($s->immobile->nome ?? 'Sconosciuta'),
                    'immobile_nome' => $s->immobile ? $s->immobile->nome . " (Int. " . $s->immobile->interno . ")" : 'N/D',
                    'ruolo' => $ruolo ?? ($s->anagrafica_id ? 'Anagrafica' : 'Condominio'),
                    'importo' => $s->saldo_iniziale,
                    'is_debito' => $s->saldo_iniziale > 0,
                    'immobile_id' => $s->immobile_id
                ];
            });

        return response()->json($saldi);
    }

    /**
     * Elimina un Piano Rate dal sistema.
     * Applica controlli rigidi (Il Muro Contabile):
     * 1. Blocca se ci sono pagamenti registrati.
     * 2. Blocca se ci sono emissioni in partita doppia (Libro Giornale).
     * 3. Blocca se il piano è Approvato (forzando il ripristino in Bozza per eliminare gli eventi dello scadenziario).
     * Se i controlli passano, elimina il piano e rimuove i lucchetti (is_applicato=false) dai saldi originari.
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @param PianoRate $pianoRate Il piano rate da distruggere
     * @return RedirectResponse Redirect alla lista dei piani rate con messaggio di successo o errore
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): RedirectResponse
    {
        // 1. IL MURO CONTABILE: Controlli prima di permettere l'eliminazione
        
        // A. Controllo Incassi (Pagamenti registrati)
        $hasPagamenti = $pianoRate->rate()->whereHas('rateQuote', function ($q) {
            $q->where('importo_pagato', '>', 0);
        })->exists();

        if ($hasPagamenti) {
            return back()->with($this->flashError(
                'Impossibile eliminare il Piano Rate: risultano incassi già registrati. ' .
                'Devi prima annullare le registrazioni di incasso associate a queste rate.'
            ));
        }

        // B. Controllo Emissioni (Scritture sul Libro Giornale)
        $hasEmissioni = $pianoRate->rate()->whereHas('rateQuote', function ($q) {
            $q->whereNotNull('scrittura_contabile_id');
        })->exists();

        if ($hasEmissioni) {
            return back()->with($this->flashError(
                'Impossibile eliminare il Piano Rate: le rate risultano già emesse in contabilità. ' .
                'Usa l\'opzione "Annulla Emissioni" all\'interno del piano rate prima di eliminarlo.'
            ));
        }

        // C. Controllo Approvazione (Ping-Pong Scadenziario)
        if ($pianoRate->stato === StatoPianoRate::APPROVATO) {
            return back()->with($this->flashError(
                'Impossibile eliminare un Piano Rate approvato. ' .
                'Devi prima togliere l\'approvazione (riportandolo in Bozza) affinché il sistema elimini automaticamente in modo pulito gli eventi dallo scadenziario.'
            ));
        }

        // 2. ELIMINAZIONE E SBLOCCO SALDI
        try {
            DB::beginTransaction();

            $gestione = $pianoRate->gestione;

            // Se la gestione aveva il blocco saldi attivo, lo rimuoviamo (Rollback contabile)
            if ($gestione && $gestione->saldo_applicato) {
                // Sblocca la Gestione Macro
                $gestione->update([
                    'saldo_applicato' => false,
                    'nota_saldo' => null
                ]);

                // Sblocca le singole righe Saldo Micro relative a questa gestione
                Saldo::where('gestione_id', $gestione->id)
                    ->where('is_applicato', true)
                    ->update(['is_applicato' => false]);
            }

            // Elimina fisicamente il piano rate (le logiche ON DELETE CASCADE sul database gestiranno le tabelle pivot)
            $pianoRate->delete();
            
            DB::commit();

            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                'condominio' => $condominio->id, 
                'esercizio' => $esercizio->id
            ])->with($this->flashSuccess(__('gestionale.success_delete_piano_rate')));

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error("Errore cancellazione piano rate", ['msg' => $e->getMessage()]);
            
            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                'condominio' => $condominio->id, 
                'esercizio' => $esercizio->id
            ])->with($this->flashError(__('gestionale.error_delete_piano_rate')));
        }
    }

    /**
     * Rimuove uno specifico capitolo di spesa da un Piano Rate esistente.
     * Elimina e ricalcola le rate basandosi sui capitoli rimanenti, verificando
     * che il capitolo da eliminare non sia vincolato da incassi, emissioni 
     * o spostamenti manuali di budget (BudgetMovement).
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @param PianoRate $pianoRate Il piano rate target
     * @param int $capitoloId L'ID del conto/capitolo da sganciare
     * @return RedirectResponse Redirect alla vista di dettaglio con esito operazione
     */
    public function detachCapitolo(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, $capitoloId)
    {
        if ($pianoRate->rate()->whereHas('rateQuote', fn($q) => $q->where('importo_pagato', '>', 0))->exists()) {
            return back()->with($this->flashError("Impossibile modificare: ci sono incassi registrati."));
        }
        
        if ($pianoRate->rate()->whereHas('rateQuote', fn($q) => $q->whereNotNull('scrittura_contabile_id'))->exists()) {
            return back()->with($this->flashError("Annulla le emissioni prima di modificare le voci."));
        }

        $isInvolved = BudgetMovement::query()
            ->where(function ($query) use ($capitoloId) {
                $query->where('source_conto_id', $capitoloId)
                      ->orWhere('destination_conto_id', $capitoloId);
            })
            ->exists();

        if ($isInvolved) {
            return back()->with($this->flashError(
                "Impossibile rimuovere: questa voce è vincolata da movimenti di budget (anche da altri piani rate). " .
                "Devi prima annullare i movimenti o restituire i fondi, poi potrai cancellarla."
            ));
        }

        try {
            DB::beginTransaction();
            
            // Sgancia il capitolo, elimina le rate attuali e le ricalcola
            $pianoRate->capitoli()->detach($capitoloId);
            $pianoRate->rate()->delete();
            
            app(GeneratePianoRateAction::class)->execute($pianoRate, null); 
            
            DB::commit();
            return back()->with($this->flashSuccess("Voce rimossa e ricalcolata."));
            
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with($this->flashError("Errore durante la rimozione: " . $e->getMessage()));
        }
    }

    /**
     * Helper di redirezione centralizzato.
     * Restituisce un feedback all'utente a seconda che il piano rate sia stato
     * solo creato in bozza o anche popolato fisicamente di rate.
     *
     * @param Condominio $condominio
     * @param Esercizio $esercizio
     * @param PianoRate $pianoRate
     * @param array $validated L'array dei campi validati
     * @param array $statistiche Statistiche generate dalla action (non usate al momento)
     * @return RedirectResponse
     */
    protected function redirectSuccess(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, array $validated, array $statistiche = []) 
    {
        $message = !empty($validated['genera_subito']) 
            ? "Piano rate creato e generato con successo!" 
            : "Piano rate creato con successo!";
            
        return redirect()->route('admin.gestionale.esercizi.piani-rate.show', [
            'condominio' => $condominio->id, 
            'esercizio' => $esercizio->id, 
            'pianoRate' => $pianoRate->id
        ])->with('success', $message);
    }
}