<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Segnalazioni;

use App\Services\RegistroModifiche\RegistroModifiche;
use App\Events\Notifiche\DestinatariDaAvvisare;
use App\Events\Segnalazioni\NotifyUserOfApprovedSegnalazione;
use App\Events\Segnalazioni\NotifyUserOfCreatedSegnalazione;
use App\Services\Notifiche\DestinatariNotifica;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Segnalazione\CreateSegnalazioneRequest;
use App\Http\Requests\Segnalazione\SegnalazioneIndexRequest;
use App\Http\Resources\Allegati\AllegatoResource;
use App\Http\Resources\Anagrafica\AnagraficaNomeResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Allegati\AllegatoService;
use App\Services\SegnalazioneService;
use App\Support\Allegati\RegoleAllegati;
use App\Support\Commenti\RegoleCommenti;
use App\Support\Segnalazioni\ContatoriElencoSegnalazioni;
use App\Support\Segnalazioni\AssegnazioneSegnalazioni;
use App\Traits\HandleFlashMessages;
use App\Traits\PaginaElenco;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Illuminate\Support\Arr;

class SegnalazioneController extends Controller
{
    use HandleFlashMessages, PaginaElenco;

    /**
     * SegnalazioneController constructor.
     *
     * @param SegnalazioneService $segnalazioneService
     */
    public function __construct(
        private SegnalazioneService $segnalazioneService,
    ) {}

    /**
     * Display a listing of segnalazioni.
     *
     * This method handles the retrieval and display of segnalazioni based on the validated filter parameters from the request.
     * It first authorizes the user for the given segnalazione and then retrieves the list of segnalazioni using the `segnalazioneService`.
     * The results are returned to the frontend via an Inertia.js response with pagination data and filters.
     *
     * @param  \App\Http\Requests\Segnalazione\SegnalazioneIndexRequest  $request  The request object containing the filter parameters.
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione model instance used for authorization.
     * @return \Inertia\Response Returns the rendered Inertia.js response with segnalazioni data, pagination, and filters.
     */
    public function index(SegnalazioneIndexRequest $request, Segnalazione $segnalazione): Response
    {
        Gate::authorize('view', $segnalazione);

        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, prima di passare il tutto alla Service: la
        // scelta esplicita se c'è, altrimenti quella già fatta dall'utente su questo elenco.
        $validated['per_page'] = $this->righePerPagina($request);

        $segnalazioni = $this->segnalazioneService->getSegnalazioni(  
            anagrafica: null,
            condominioIds: null,
            validated: $validated
        );

        // Get stats using the same service
        $stats = $this->segnalazioneService->getSegnalazioniStats();

        // Decisione 453: quanti commenti e allegati ha ogni riga, e quanti da approvare.
        ContatoriElencoSegnalazioni::mettiSulleRighe($request->user(), $segnalazioni->getCollection(), 'admin');

        return Inertia::render('segnalazioni/SegnalazioniList', [
            'segnalazioni' => SegnalazioneResource::collection($segnalazioni)->resolve(),
            'stats' => $stats, 
            'meta' => [
                'current_page' => $segnalazioni->currentPage(),
                'last_page'    => $segnalazioni->lastPage(),
                'per_page'     => $segnalazioni->perPage(),
                'total'        => $segnalazioni->total(),
            ],
            // Filtri per ripristinare lo stato nel frontend
            'filters' => Arr::only($validated, ['subject', 'priority', 'stato', 'condominio_id', 'visibility', 'assegnazione']),
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
            'assegnatari' => AssegnazioneSegnalazioni::assegnatari(),
        ]);
    }
  
    /**
     * Show the form for creating a new segnalazione.
     *
     * This method authorizes the user to create a new segnalazione and then returns the Inertia.js response 
     * to render the creation form. The form includes a list of all condomini and anagrafiche, fetched from 
     * the respective resources.
     *
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione model used for authorization.
     * @return \Inertia\Response Returns the rendered Inertia.js response with the list of condomini and anagrafiche.
     */
    public function create(Segnalazione $segnalazione): Response
    {
        Gate::authorize('create', $segnalazione);

        // Della rubrica la tendina «Anagrafiche» legge l'id e il nome (Coda 408): il resto della persona sta nella sua
        // scheda, che chiede «Visualizza anagrafiche».
        return Inertia::render('segnalazioni/SegnalazioniNew',[
            'condomini'        => CondominioResource::collection(Condominio::all()),
            'anagrafiche'      => AnagraficaNomeResource::collection(Anagrafica::get(['id', 'nome'])),
            'allegati_massimo' => RegoleAllegati::massimoPerElemento(),
            // Coda 683 (1): il limite vero di un file e i formati, come il riquadro della scheda: il modulo non scrive più «10 MB» fisso.
            'allegati_limite_file' => \App\Support\LimiteCaricamento::etichetta(),
            'allegati_formati' => RegoleAllegati::FORMATI,
            'commenti_segnalazioni_moderati_di_serie' => (bool) app(\App\Settings\GeneralSettings::class)->commenti_segnalazioni_moderati_di_serie,
            // «Commenti in moderazione» la sceglie solo chi modera: per gli altri vale il di serie.
            'puo_scegliere_moderazione' => RegoleCommenti::puoScegliereModerazione(request()->user(), 'segnalazioni'),
            'assegnazione'     => AssegnazioneSegnalazioni::perLaPagina(Auth::user()),
        ]); 
    }

    /**
     * Store a newly created segnalazione in the database.
     *
     * This method handles the process of creating a new segnalazione, including validating the incoming request,
     * attaching any related anagrafiche, and committing the transaction. It also sends notifications to users 
     * upon successful creation.
     * If an error occurs during the creation process, it will roll back the transaction and log the error.
     *
     * @param  \App\Http\Requests\Segnalazione\CreateSegnalazioneRequest  $request  The request object containing validated data for the segnalazione.
     * @param  \App\Models\Segnalazione  $segnalazione  The segnalazione model used for authorization.
     * @return \Illuminate\Http\RedirectResponse A redirect response to the segnalazioni index with a success or error message.
     * 
     * @throws \Exception If an error occurs during the creation process, an exception is thrown, and the transaction is rolled back.
     */
    public function store(CreateSegnalazioneRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('create', $segnalazione);
        
        $validated = $request->validated(); 

        // «Commenti in moderazione» la sceglie solo chi modera: per gli altri il campo si ignora e vale il di serie (`Segnalazione::creating`).
        if (! RegoleCommenti::puoScegliereModerazione($request->user(), 'segnalazioni')) {
            unset($validated['commenti_moderati']);
        }

        if ($request->hasFile('allegati')) {
            abort_unless($request->user()->hasPermissionTo(RegoleAllegati::permesso('segnalazioni', 'carica')), 403);
        }

        try {

            DB::beginTransaction();

            $segnalazione = Segnalazione::create(Arr::except($validated, ['assigned_to']));

            if (!empty($validated['anagrafiche'])) {
                RegistroModifiche::legame($segnalazione, 'anagrafiche', 'Persone', fn () => $segnalazione->anagrafiche()->attach($validated['anagrafiche']));
            }

            if ($request->hasFile('allegati')) {
                abort_unless(RegoleAllegati::puoCaricare($request->user(), $segnalazione), 403);
                app(AllegatoService::class)->carica(
                    $request->user(),
                    $segnalazione,
                    (array) $request->file('allegati', []),
                    inCreazione: true
                );
            }

            DB::commit();

            if (! empty($validated['assigned_to'])) {
                $nuovoAssegnatario = User::find($validated['assigned_to']);
                AssegnazioneSegnalazioni::assegna($segnalazione, $nuovoAssegnatario, $request->user());
            }

            NotifyUserOfCreatedSegnalazione::dispatch($segnalazione);

            return to_route('admin.segnalazioni.index')->with(
                $this->flashSuccess(__('segnalazioni.success_create_ticket'))
            );

        } catch (\Exception $e) {
        
            DB::rollback();

            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                throw $e;
            }

            Log::error('Error creating segnalazione: ' . $e->getMessage());

            return to_route('admin.segnalazioni.index')->with(
                $this->flashError(__('segnalazioni.error_create_ticket'))
            );

        }

    }

    /**
     * Display the specified segnalazione.
     *
     * @param Segnalazione $segnalazione
     * @return Response
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(Segnalazione $segnalazione): Response
    {
        Gate::authorize('view', $segnalazione);

        $user = request()->user();
        $canModerate = RegoleCommenti::puoModerare($user, $segnalazione);

        // Chiudi l'evento di moderazione/commento nell'Admin Inbox se aperto
        if ($canModerate) {
            Evento::where('tipo', 'commento')
                ->where('eventable_type', $segnalazione->getMorphClass())
                ->where('eventable_id', $segnalazione->id)
                ->where('is_completed', false)
                ->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);
        }

        $segnalazioneCaricata = Segnalazione::with([
            'createdBy.anagrafica',
            'assignedTo',
            'assegnataDa',
            'condominio',
            'anagrafiche',
            'commenti.autore.anagrafica',
        ])->findOrFail($segnalazione->id);

        // Per i moderatori, carica anche i commenti in attesa e nascosti
        if ($canModerate) {
            $segnalazioneCaricata->load(['commentiInAttesa.autore.anagrafica', 'commentiNascosti.autore.anagrafica']);
        }

        return Inertia::render('segnalazioni/SegnalazioniView', [
            'segnalazione' => new SegnalazioneResource($segnalazioneCaricata),
            'assegnazione' => AssegnazioneSegnalazioni::perLaPagina($user),
            // Coda 671: lo stato e la priorità si cambiano dalla scheda con lo stesso permesso di «Modifica».
            'puo_modificare' => Gate::forUser($user)->allows('update', $segnalazioneCaricata),
            'commenti_config' => RegoleCommenti::configSchedaSegnalazione($user, $segnalazioneCaricata, 'admin'),
            // Coda 304: la conferma di «Rendi pubblica» dice, prima del comando, se l'autore non la
            // vedrà più dal portale (non è più nel palazzo); la mail che riceve lo dice anche a lui.
            'rendi_pubblica' => [
                'autore_perde_la_scheda' => $segnalazioneCaricata->autorePerdeLaSchedaConRendiPubblica(),
            ],
            'allegati' => AllegatoResource::perScheda(
                RegoleAllegati::visibiliInScheda($user, $segnalazioneCaricata),
                $user,
                $segnalazioneCaricata,
                'admin'
            ),
            'allegati_config' => RegoleAllegati::configScheda($user, $segnalazioneCaricata),
        ]);
    }

    /**
     * Show the form for editing the specified segnalazione.
     *
     * @param Segnalazione $segnalazione
     * @return Response
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function edit(Segnalazione $segnalazione): Response
    {
        Gate::authorize('update', $segnalazione);
        
        $user = Auth::user();
        $segnalazione->loadMissing(['createdBy', 'assignedTo', 'assegnataDa', 'condominio', 'anagrafiche']);

        // Della rubrica la tendina e i destinatari già scelti leggono l'id e il nome (Coda 408).
        return Inertia::render('segnalazioni/SegnalazioniEdit', [
            'segnalazione'  => new SegnalazioneResource($segnalazione),
            'assegnazione'  => AssegnazioneSegnalazioni::perLaPagina($user),
            'condomini'     => CondominioOptionsResource::collection(Condominio::all()),
            'anagrafiche'   => AnagraficaNomeResource::collection(Anagrafica::get(['id', 'nome'])),
            // «Commenti in moderazione» la cambia solo chi modera: per gli altri la pagina non mostra la casella e il server ignora il campo.
            'puo_scegliere_moderazione' => RegoleCommenti::puoImpostareModerazione(request()->user(), $segnalazione),
        ]);
    }

    /**
     * Update the specified segnalazione in storage.
     *
     * @param CreateSegnalazioneRequest $request
     * @param Segnalazione $segnalazione
     * @return RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(CreateSegnalazioneRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('update', $segnalazione);

        $validated = $request->validated(); 
        $eraApprovata = (bool) $segnalazione->is_approved;
        $haApprovatoAdesso = ! $eraApprovata && array_key_exists('is_approved', $validated) && (bool) $validated['is_approved'];

        // «Commenti in moderazione» la cambia solo chi modera: per gli altri il campo si ignora e resta com'era.
        if (! RegoleCommenti::puoImpostareModerazione($request->user(), $segnalazione)) {
            unset($validated['commenti_moderati']);
        }

        /*
         * ⚠️ **I destinatari si leggono PRIMA di toccare le pivot** — vedi la nota gemella in
         * `Comunicazioni\ComunicazioneController::update()`, dove sta il perché per esteso.
         */
        $risolutore = app(DestinatariNotifica::class);
        $destinatariPrima = $risolutore->perModello($segnalazione);

        try {

            DB::beginTransaction();

            // ⚠️ `updated_by` non arriva dal modulo: lo mette il server. Un campo che decide
            // «chi ha fatto questo» non si accetta mai da chi manda la richiesta.
            $datiAggiornamento = Arr::except($validated, ['assigned_to']);
            $segnalazione->update($datiAggiornamento + ['updated_by' => Auth::id()]);

            // Coda 307: i destinatari si toccano solo se il modulo li manda («senza campo = non
            // toccare», la stessa regola delle categorie dei documenti). La chiave presente e vuota
            // vuol dire «nessuno».
            if (array_key_exists('anagrafiche', $validated)) {
                RegistroModifiche::legame($segnalazione, 'anagrafiche', 'Persone', fn () => $segnalazione->anagrafiche()->sync($validated['anagrafiche'] ?? []));
            }

            DB::commit();

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating segnalazione: ' . $e->getMessage());

            return to_route('admin.segnalazioni.index')->with(
                $this->flashError(__('segnalazioni.error_update_ticket'))
            );
            
        }

        if (array_key_exists('assigned_to', $validated)) {
            $nuovoAssegnatario = ! empty($validated['assigned_to']) ? User::find($validated['assigned_to']) : null;
            AssegnazioneSegnalazioni::assegna($segnalazione, $nuovoAssegnatario, $request->user());
        }

        try {

            if ($haApprovatoAdesso) {
                NotifyUserOfApprovedSegnalazione::dispatch($segnalazione, Auth::user());
            } else {
                $this->avvisaDopoLaModifica($segnalazione, $destinatariPrima, $validated);
            }

        } catch (\Exception $emailException) {

            Log::error('Error notifying update for segnalazione ID ' . $segnalazione->id . ': ' . $emailException->getMessage());

            return to_route('admin.segnalazioni.index')->with(
                $this->flashWarning(__('segnalazioni.error_notify_updated_ticket'))
            );

        }

        return to_route('admin.segnalazioni.index')->with(
            $this->flashSuccess(__('segnalazioni.success_update_ticket'))
        );
    }

    /**
     * Chi va avvisato dopo una modifica, e con quale dei due avvisi.
     *
     * Gemella di `Comunicazioni\ComunicazioneController::avvisaDopoLaModifica()`, dove sta la
     * spiegazione per esteso: i nuovi arrivati ricevono l'avviso di *creazione* sempre, chi c'era
     * già riceve quello di *modifica* solo se l'amministratore spunta la casella. L'autore non è mai
     * fra i nuovi arrivati: non riceve «nuova segnalazione» della propria, come all'approvazione
     * (`SendApprovedSegnalazioneNotificationToUser`).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $destinatariPrima
     * @param  array<string, mixed>  $validated
     */
    private function avvisaDopoLaModifica(Segnalazione $oggetto, $destinatariPrima, array $validated): void
    {
        $destinatariDopo = app(DestinatariNotifica::class)->perModello($oggetto->fresh());

        $dellAutore = $oggetto->created_by === null
            ? collect()
            : Anagrafica::where('user_id', $oggetto->created_by)->pluck('id');

        $nuovi = $destinatariDopo->diff($destinatariPrima)->diff($dellAutore)->values()->all();

        if ($nuovi !== []) {
            DestinatariDaAvvisare::dispatch($oggetto, $nuovi, 'nuovo');
        }

        if (! ($validated['avvisa_destinatari'] ?? false)) {
            return;
        }

        $giaDestinatari = $destinatariDopo->intersect($destinatariPrima)->values()->all();

        if ($giaDestinatari !== []) {
            DestinatariDaAvvisare::dispatch($oggetto, $giaDestinatari, 'aggiornato');
        }
    }

    /** Gli stati lavorazione e le priorità che una segnalazione può avere: le colonne di `segnalazioni` (Coda 671). */
    public const STATI = ['aperta', 'in lavorazione', 'chiusa'];

    public const PRIORITA = ['bassa', 'media', 'alta', 'urgente'];

    /**
     * Le azioni veloci della scheda (Coda 671, decisioni 466 (2) e 487): lo stato lavorazione o la priorità, uno alla volta, si salvano subito per la
     * stessa strada di «Modifica»: lo stesso permesso (`SegnalazionePolicy::update`), chi ha cambiato in `updated_by`, il salvataggio del modello
     * (che chiude il compito dell'Action inbox con «Chiusa» e lo riapre riaprendo, `CompitoDellaSegnalazione`), e gli stessi avvisi dopo la
     * modifica senza la casella «Avvisa i destinatari»: i destinatari non cambiano, quindi non parte nessuna email, come da «Modifica».
     * Torna alla scheda; la pagina rilegge solo la segnalazione.
     */
    public function aggiornaParametro(\Illuminate\Http\Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('update', $segnalazione);

        // P6 (Fase 1-bis della beta.12): ogni rifiuto dice che cosa scegliere, con le parole della pagina (si legge sotto il valore, `ParametroVeloce.vue`).
        $dati = $request->validate([
            'stato' => ['required_without:priority', 'prohibits:priority', 'string', \Illuminate\Validation\Rule::in(self::STATI)],
            'priority' => ['required_without:stato', 'string', \Illuminate\Validation\Rule::in(self::PRIORITA)],
        ], [
            'stato.required_without' => __('segnalazioni.quick.nothing_chosen'),
            'priority.required_without' => __('segnalazioni.quick.nothing_chosen'),
            'stato.prohibits' => __('segnalazioni.quick.one_at_a_time'),
            'stato.string' => __('segnalazioni.quick.invalid_status'),
            'stato.in' => __('segnalazioni.quick.invalid_status'),
            'priority.string' => __('segnalazioni.quick.invalid_priority'),
            'priority.in' => __('segnalazioni.quick.invalid_priority'),
        ]);

        $destinatariPrima = app(DestinatariNotifica::class)->perModello($segnalazione);
        $segnalazione->update($dati + ['updated_by' => Auth::id()]);
        $this->avvisaDopoLaModifica($segnalazione, $destinatariPrima, $dati);

        return back();
    }

    /**
     * Toggle the 'locked' status of a segnalazione (resolve/unresolve).
     *
     * @param Segnalazione $segnalazione
     * @return RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function toggleResolve(Segnalazione $segnalazione): RedirectResponse
    {

       Gate::authorize('update', $segnalazione);

        // Determine the intended new state before changing it
        $willBeLocked = !$segnalazione->is_locked;

        try {

            // Apply the state change
            $segnalazione->is_locked = $willBeLocked;
            $segnalazione->save();

            // Success message based on new state
            $message = $willBeLocked
                ? __('segnalazioni.success_lock_ticket')    
                : __('segnalazioni.success_unlock_ticket');

            return back()->with(
                $this->flashSuccess($message)
            );

        } catch (\Exception $e) {

            Log::error('Error toggling resolve status segnalazione ID ' . $segnalazione->id . ': ' . $e->getMessage());

            // Error message based on intended state
            $errorMessage = $willBeLocked
                ? __('segnalazioni.error_lock_ticket')
                : __('segnalazioni.error_unlock_ticket');

            return back()->with(
                $this->flashError($errorMessage)
            );
            
        }
        
    }

    /**
     * Remove the specified segnalazione from storage.
     *
     * @param Segnalazione $segnalazione
     * @return RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy(Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('delete', $segnalazione);

        try {

            $segnalazione->delete();

            return back()->with(
                $this->flashSuccess(__('segnalazioni.success_delete_ticket'))
            );

        } catch (\Exception $e) {
            
            Log::error('Error deleting segnalazione: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('segnalazioni.error_delete_ticket'))
            );
        }
       
    }
}
