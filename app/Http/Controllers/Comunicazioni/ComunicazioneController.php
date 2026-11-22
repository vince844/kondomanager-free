<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Comunicazioni;

use App\Services\RegistroModifiche\RegistroModifiche;
use App\Events\Comunicazioni\NotifyUserOfCreatedComunicazione;
use App\Events\Notifiche\DestinatariDaAvvisare;
use App\Services\Notifiche\DestinatariNotifica;
use App\Http\Controllers\Controller;
use App\Traits\HandleFlashMessages;
use App\Traits\PaginaElenco;
use App\Http\Requests\Comunicazione\ComunicazioneIndexRequest;
use App\Http\Requests\Comunicazione\CreateComunicazioneRequest;
use App\Http\Resources\Allegati\AllegatoResource;
use App\Http\Resources\Anagrafica\AnagraficaNomeResource;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Evento;
use App\Services\Allegati\AllegatoService;
use App\Services\ComunicazioneService;
use App\Support\Allegati\RegoleAllegati;
use App\Support\Commenti\RegoleCommenti;
use App\Support\Comunicazioni\ContatoriElenco;
use App\Support\Comunicazioni\PubblicazioneProgrammata;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Response;
use Illuminate\Support\Facades\Gate;

class ComunicazioneController extends Controller
{
    use HandleFlashMessages, PaginaElenco;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ComunicazioneService 
     */
    public function __construct(
        private ComunicazioneService $comunicazioneService,
    ) {}

    /**
     * Displays a list of comunicazioni based on validated filter parameters.
     *
     * This method handles the index route for comunicazioni. It validates the incoming request,
     * fetches the filtered list of comunicazioni via the service layer, and renders the Inertia view
     * with the data and pagination metadata.
     *
     * Currently, it passes `null` for `anagrafica` and `condominioIds`, meaning it retrieves all comunicazioni
     * that match the validated filter criteria (like subject or priority).
     *
     * @param  \App\Http\Requests\ComunicazioneIndexRequest $request  The validated request containing filter inputs.
     * @param  \App\Models\Comunicazione $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\Response The Inertia view with a list of comunicazioni, pagination metadata, and filters applied.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to view the comunicazione.
     */
    public function index(ComunicazioneIndexRequest $request, Comunicazione $comunicazione): Response
    {
        Gate::authorize('view', $comunicazione);

        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, prima di passare il tutto alla Service: la
        // scelta esplicita se c'è, altrimenti quella già fatta dall'utente su questo elenco.
        $validated['per_page'] = $this->righePerPagina($request);

        $comunicazioni = $this->comunicazioneService->getComunicazioni(  
            anagrafica: null,
            condominioIds: null, // Questo era il parametro fisso per la visualizzazione dell'utente, lo lasciamo a null
            validated: $validated  // Passiamo tutto l'array validato al service
        ); 

        $stats = $this->comunicazioneService->getComunicazioniStats();

        // Decisione 441: quanti commenti e allegati ha ogni riga, e quanti da approvare.
        ContatoriElenco::mettiSulleRighe(request()->user(), $comunicazioni->getCollection(), 'admin');

        return Inertia::render('comunicazioni/ComunicazioniList', [
            'comunicazioni' => ComunicazioneResource::collection($comunicazioni)->resolve(), 
            'stats' => $stats,
            'meta' => [
                'current_page' => $comunicazioni->currentPage(),
                'last_page'    => $comunicazioni->lastPage(),
                'per_page'     => $comunicazioni->perPage(),
                'total'        => $comunicazioni->total(),
            ],
            // Passiamo anche condominio_id e visibility al frontend per ripristinare lo stato
            'filters' => Arr::only($validated, ['subject', 'priority', 'condominio_id', 'visibility']),
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Show the form to create a new comunicazione.
     *
     * This method first authorizes the user to create a new comunicazione.
     * Then it renders the Inertia.js page for creating a comunicazione, providing
     * a list of all condomini and an empty anagrafiche list.
     *
     * @param  \App\Models\Comunicazione $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\Response The Inertia view for creating a new comunicazione.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to create a comunicazione.
     */
    public function create(Comunicazione $comunicazione): Response
    {
        Gate::authorize('create', $comunicazione);

        return Inertia::render('comunicazioni/ComunicazioniNew',[
            'condomini'        => CondominioResource::collection(Condominio::all()),
            'anagrafiche'      => [],
            'allegati_massimo' => RegoleAllegati::massimoPerElemento(),
            // Coda 683 (1): il limite vero di un file e i formati, come il riquadro della scheda: il modulo non scrive più «10 MB» fisso.
            'allegati_limite_file' => \App\Support\LimiteCaricamento::etichetta(),
            'allegati_formati' => RegoleAllegati::FORMATI,
            'commenti_moderati_di_serie' => (bool) app(\App\Settings\GeneralSettings::class)->commenti_comunicazioni_moderati_di_serie,
            // «Commenti in moderazione» la sceglie solo chi modera: per gli altri vale il di serie.
            'puo_scegliere_moderazione' => RegoleCommenti::puoScegliereModerazione(request()->user(), 'comunicazioni'),
            'programmazione'   => [
                'puo_programmare'      => PubblicazioneProgrammata::puoProgrammare(Auth::user()),
                'fuso'                 => PubblicazioneProgrammata::fuso(),
                'pianificatore_attivo' => PubblicazioneProgrammata::pianificatoreAttivo(),
                'adesso'               => now()->setTimezone(PubblicazioneProgrammata::fuso())->format('Y-m-d\TH:i'),
            ],
            // «Avviso anche in bacheca» dalla convocazione di un'assemblea: la comunicazione si apre già scritta, con le frasi pubbliche dei punti.
            'precompilata'     => $this->avvisoDellAssemblea(request()) ?? $this->avvisoDellaDelibera(request()),
            // Aperta da un'assemblea o da una cosa da fare: «Torna all'assemblea» (decisione 646).
            'ritorno'          => \App\Services\Assemblee\RitornoAllAssemblea::per(request()->user(), (int) request()->query('assemblea', 0), (int) request()->query('cosa_da_fare', 0)),
        ]);  
    }

    /**
     * «Avvisa i condòmini» dal seguito di una delibera (`?cosa_da_fare=`): la comunicazione si apre già scritta con la frase pubblica del punto, per chi
     * gestisce le assemblee; null altrimenti. @return ?array<string, mixed>
     */
    private function avvisoDellaDelibera(\Illuminate\Http\Request $request): ?array
    {
        $cosa = \App\Services\Assemblee\Seguito\AvvisoAiCondomini::cosaPer((int) $request->query('cosa_da_fare', 0), $request->user());

        return $cosa === null ? null : \App\Services\Assemblee\Seguito\AvvisoAiCondomini::precompilata($cosa);
    }

    /** Il testo dell'avviso di un'assemblea (`?assemblea=`), per chi gestisce le assemblee; null altrimenti. @return ?array<string, mixed> */
    private function avvisoDellAssemblea(\Illuminate\Http\Request $request): ?array
    {
        $id = (int) $request->query('assemblea', 0);
        if ($id === 0 || ! \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($request->user())) {
            return null;
        }
        $assemblea = \App\Models\Assemblee\Assemblea::query()->find($id);

        return $assemblea === null ? null : app(\App\Services\Assemblee\Convocazione\AvvisoInBacheca::class)->precompilata($assemblea);
    }

    /**
     * Store a newly created comunicazione in storage.
     *
     * This method handles the authorization, validation, creation, and association
     * of a new comunicazione. It also sends notifications and handles potential
     * errors using a transaction and proper logging.
     *
     * @param  \App\Http\Requests\CreateComunicazioneRequest $request  The validated request containing comunicazione data.
     * @param  \App\Models\Comunicazione $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\RedirectResponse Redirects to the index route with a success or error message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to create a comunicazione.
     */
    public function store(CreateComunicazioneRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('create', $comunicazione);

        $validated = $request->validated();

        // «Commenti in moderazione» la sceglie solo chi modera: per gli altri il campo si ignora e vale il di serie (`Comunicazione::creating`).
        if (! RegoleCommenti::puoScegliereModerazione($request->user(), 'comunicazioni')) {
            unset($validated['commenti_moderati']);
        }

        if ($request->hasFile('allegati')) {
            abort_unless($request->user()->hasPermissionTo(RegoleAllegati::permesso('comunicazioni', 'carica')), 403);
        }

        $dataProgrammata = $validated['data_programmata'] ?? null;
        $eProgrammata = ! empty($dataProgrammata);
        if ($eProgrammata) {
            $validated['is_published'] = false;
        }

        try {

            DB::beginTransaction();
        
            $comunicazione = Comunicazione::create($validated);
            if ($eProgrammata) {
                $comunicazione->programmata_per = PubblicazioneProgrammata::daModulo($dataProgrammata);
                $comunicazione->programmata_da = Auth::id();
                $comunicazione->save();
            }

            RegistroModifiche::legame($comunicazione, 'condomini', 'Condomini', fn () => $comunicazione->condomini()->attach($validated['condomini_ids'] ?? []));
        
            if (!empty($validated['anagrafiche'])) {
                RegistroModifiche::legame($comunicazione, 'anagrafiche', 'Destinatari', fn () => $comunicazione->anagrafiche()->attach($validated['anagrafiche'] ?? []));
            }

            if ($request->hasFile('allegati')) {
                abort_unless(RegoleAllegati::puoCaricare($request->user(), $comunicazione), 403);
                app(AllegatoService::class)->carica(
                    $request->user(),
                    $comunicazione,
                    (array) $request->file('allegati', []),
                    inCreazione: true
                );
            }

            // L'avviso in bacheca di un'assemblea torna legato a lei (`assemblee.comunicazione_id`), se è del suo condominio.
            if ($request->filled('assemblea_id') && \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($request->user())) {
                app(\App\Services\Assemblee\Convocazione\AvvisoInBacheca::class)->collega($comunicazione, (int) $request->input('assemblea_id'));
            }
            // «Avvisa i condòmini» di una delibera: la comunicazione torna legata alla cosa da fare; pubblicata, la cosa è fatta.
            if ($request->filled('cosa_da_fare_id')) {
                \App\Services\Assemblee\Seguito\AvvisoAiCondomini::collega($comunicazione, (int) $request->input('cosa_da_fare_id'), $request->user());
            }
        
            DB::commit();

        } catch (\Exception $e) {

            DB::rollback();

            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                throw $e;
            }
            
            Log::error('Error creating comunicazione: ' . $e->getMessage());
        
            return to_route('admin.comunicazioni.index')->with(
                $this->flashError(__('comunicazioni.error_create_communication'))
            );

        }
        
        $ritorno = \App\Services\Assemblee\RitornoAllAssemblea::per($request->user(), (int) $request->input('assemblea_id', 0),
            (int) $request->input('cosa_da_fare_id', 0), array_map('intval', (array) ($validated['condomini_ids'] ?? [])));

        if (! $eProgrammata) {
            try {

                NotifyUserOfCreatedComunicazione::dispatch($validated, $comunicazione);

            } catch (\Exception $emailException) {

                Log::error('Error sending email for comunicazione ID ' . $comunicazione->id . ': ' . $emailException->getMessage());
            
                return to_route('admin.comunicazioni.index')->with(
                    $this->flashWarning(__('comunicazioni.error_notify_new_communication'))
                );

            }
        }
        
        // Aperta da un'assemblea (decisione 646): si torna al passo da cui si è partiti, con il messaggio di ciò che è successo.
        if ($ritorno !== null) {
            if ($eProgrammata) {
                $locale = $comunicazione->programmata_per->copy()->setTimezone(PubblicazioneProgrammata::fuso());
                $frase = 'Avviso in bacheca programmato per il '.$locale->format('d/m/Y').' alle '.$locale->format('H:i').'.';
            } else {
                $frase = $comunicazione->is_published ? 'Avviso in bacheca pubblicato.' : 'Avviso in bacheca salvato in bozza: lo pubblichi da qui o dalla bacheca.';
            }

            return redirect($ritorno['url'])->with($this->flashSuccess($frase));
        }

        if ($eProgrammata) {
            $locale = $comunicazione->programmata_per->copy()->setTimezone(PubblicazioneProgrammata::fuso());

            return to_route('admin.comunicazioni.index')->with(
                $this->flashSuccess(__('comunicazioni.success_schedule_communication', [
                    'date' => $locale->format('d/m/Y'),
                    'time' => $locale->format('H:i'),
                ]))
            );
        }

        return to_route('admin.comunicazioni.index')->with(
            $this->flashSuccess(__('comunicazioni.success_create_communication'))
        );

    }

    /**
     * Display the specified comunicazione.
     *
     * This method handles the authorization check for the current user to view the specified
     * comunicazione. It then fetches the related data (e.g., the `createdBy` relationship with its `anagrafica`)
     * and passes the information to the Inertia view for rendering.
     *
     * @param \App\Models\Comunicazione $comunicazione The comunicazione to be displayed.
     * @return \Inertia\Response The Inertia response containing the view and data.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view the comunicazione.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the comunicazione is not found.
     */
    public function show(Comunicazione $comunicazione): Response
    {
        Gate::authorize('show', $comunicazione);

        $user = request()->user();
        $comunicazioneCaricata = Comunicazione::with('createdBy.anagrafica')->findOrFail($comunicazione->id);

        if (RegoleCommenti::puoModerare($user, $comunicazioneCaricata)) {
            Evento::where('tipo', 'commento')
                ->where('eventable_type', $comunicazioneCaricata->getMorphClass())
                ->where('eventable_id', $comunicazioneCaricata->id)
                ->where('is_completed', false)
                ->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);
        }

        return Inertia::render('comunicazioni/ComunicazioniView', [
            'comunicazione' => new ComunicazioneResource($comunicazioneCaricata),
            'allegati' => AllegatoResource::perScheda(
                RegoleAllegati::visibiliInScheda($user, $comunicazioneCaricata),
                $user,
                $comunicazioneCaricata,
                'admin'
            ),
            'allegati_config' => RegoleAllegati::configScheda($user, $comunicazioneCaricata),
            'commenti_config' => RegoleCommenti::configSchedaComunicazione($user, $comunicazioneCaricata),
            'discussioni'     => RegoleCommenti::discussioniInScheda($user, $comunicazioneCaricata, 'admin'),
            // Decisione 467: i destinatari nella card dei parametri, solo nel pannello (il portale non li riceve: Coda 668).
            'destinatari'     => $this->destinatariPerScheda($comunicazioneCaricata),
        ]);
    }

    /**
     * I destinatari della comunicazione per la card della scheda del pannello (decisione 467): le persone per nome, se ce ne sono; altrimenti,
     * per una privata, nessun nome («Privata, con lo studio»); altrimenti i condomìni per nome.
     *
     * @return array{tipo: 'persone'|'privata'|'condomini', totale: int, nomi: list<string>}
     */
    private function destinatariPerScheda(Comunicazione $comunicazione): array
    {
        $persone = $comunicazione->anagrafiche()->orderBy('anagrafiche.nome')->pluck('anagrafiche.nome')->map(fn ($n) => (string) $n)->values()->all();
        if ($persone !== []) {
            return ['tipo' => 'persone', 'totale' => count($persone), 'nomi' => $persone];
        }

        if ($comunicazione->is_private) {
            return ['tipo' => 'privata', 'totale' => 0, 'nomi' => []];
        }

        $condomini = $comunicazione->condomini()->orderBy('condomini.nome')->pluck('condomini.nome')->map(fn ($n) => (string) $n)->values()->all();

        return ['tipo' => 'condomini', 'totale' => count($condomini), 'nomi' => $condomini];
    }

    /**
     * Show the form for editing the specified Comunicazione.
     *
     * Authorizes the user to edit the comunicazione and loads the necessary relationships
     * such as condomini and anagrafiche (id and nome only). The method then renders the edit view
     * using Inertia with the data required for the form.
     *
     * @param  \App\Models\Comunicazione $comunicazione
     * @return \Illuminate\Http\Response
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the comunicazione
     */
    public function edit(Comunicazione $comunicazione): Response
    {
        Gate::authorize('update', $comunicazione);

        // Delle persone la pagina legge soltanto id e nome, nell'elenco e nei destinatari già scelti (Coda 408): il resto
        // resta nella scheda della persona.
        $comunicazione->loadMissing(['condomini', 'anagrafiche:id,nome']);

        return Inertia::render('comunicazioni/ComunicazioniEdit', [
         'comunicazione' => new ComunicazioneResource($comunicazione),
         'condomini'     => CondominioOptionsResource::collection(Condominio::all()),
         'anagrafiche'   => AnagraficaNomeResource::collection(Anagrafica::get(['id', 'nome'])),
         // «Commenti in moderazione» la cambia solo chi modera: per gli altri resta com'è.
         'puo_scegliere_moderazione' => RegoleCommenti::puoImpostareModerazione(request()->user(), $comunicazione),
         'programmazione' => [
             'puo_programmare'      => PubblicazioneProgrammata::puoProgrammare(Auth::user(), $comunicazione),
             'fuso'                 => PubblicazioneProgrammata::fuso(),
             'pianificatore_attivo' => PubblicazioneProgrammata::pianificatoreAttivo(),
             'adesso'               => now()->setTimezone(PubblicazioneProgrammata::fuso())->format('Y-m-d\TH:i'),
         ],
        ]);
    }

    /**
     * Update the specified Comunicazione in storage.
     *
     * Authorizes the user to update the comunicazione, performs the update, and syncs associated condomini and anagrafiche.
     * If an error occurs during the update, a log entry is created, and the user is notified.
     *
     * @param  \App\Http\Requests\CreateComunicazioneRequest $request
     * @param  \App\Models\Comunicazione $comunicazione
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the comunicazione
     * @throws \Exception If the update fails
     */
    public function update(CreateComunicazioneRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('update', $comunicazione);

        $validated = $request->validated(); 

        // «Commenti in moderazione» la cambia solo chi modera: per gli altri il campo si ignora e resta com'era.
        if (! RegoleCommenti::puoImpostareModerazione($request->user(), $comunicazione)) {
            unset($validated['commenti_moderati']);
        }

        $haCampoProgrammata = array_key_exists('data_programmata', $request->all());
        if ($haCampoProgrammata && ! empty($request->input('data_programmata'))) {
            $validated['is_published'] = false;
        }

        /*
         * ⚠️ **I destinatari si leggono PRIMA di toccare le pivot**, ed è tutto il senso della
         * correzione della beta.64. Fino a qui la platea si fissava alla creazione: chi veniva
         * aggiunto in modifica non riceveva **niente**, né allora né mai. Non era «non lo
         * avvisiamo di una modifica» — a lui la comunicazione non era mai arrivata, e la vedeva
         * solo entrando a guardare.
         */
        $risolutore = app(DestinatariNotifica::class);
        $destinatariPrima = $risolutore->perModello($comunicazione);

        try {

            DB::beginTransaction();

            // ⚠️ `updated_by` non arriva dal modulo: lo mette il server. Un campo che decide
            // «chi ha fatto questo» non si accetta mai da chi manda la richiesta.
            $comunicazione->update($validated + ['updated_by' => Auth::id()]);

            if ($haCampoProgrammata) {
                $dataProgrammata = $request->input('data_programmata');
                if (empty($dataProgrammata)) {
                    $comunicazione->programmata_per = null;
                    $comunicazione->programmata_da = null;
                    $comunicazione->save();
                } else {
                    $nuovaOraUtc = PubblicazioneProgrammata::daModulo($dataProgrammata);
                    $oraPrecedenteUtc = $comunicazione->programmata_per?->format('Y-m-d H:i:s');
                    $nuovaOraUtcFormatted = $nuovaOraUtc?->format('Y-m-d H:i:s');

                    $comunicazione->programmata_per = $nuovaOraUtc;
                    if ($oraPrecedenteUtc !== $nuovaOraUtcFormatted) {
                        $comunicazione->programmata_da = Auth::id();
                    }
                    $comunicazione->save();
                }
            }

            RegistroModifiche::legame($comunicazione, 'condomini', 'Condomini', fn () => $comunicazione->condomini()->sync($validated['condomini_ids'] ?? []));

            // Coda 307: i destinatari si toccano solo se il modulo li manda («senza campo = non
            // toccare», la stessa regola delle categorie dei documenti): una richiesta senza il
            // campo lascia la comunicazione indirizzata a chi lo era. La chiave presente e vuota
            // vuol dire «nessuno».
            if (array_key_exists('anagrafiche', $validated)) {
                RegistroModifiche::legame($comunicazione, 'anagrafiche', 'Destinatari', fn () => $comunicazione->anagrafiche()->sync($validated['anagrafiche'] ?? []));
            }

            // I destinatari decidono le discussioni: una persona tolta porta via i commenti della sua dal compito «Da moderare», un
            // palazzo tolto quelli scritti da lì (decisione 459), e così il passaggio fra palazzi e persone. Le tabelle
            // ponte non hanno gli eventi dei modelli, quindi il compito si porta a com'è da qui.
            app(\App\Services\Gestionale\Inbox\CompitoDaModerare::class)->sincronizza($comunicazione->fresh() ?? $comunicazione);

            DB::commit();

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating comunicazione: ' . $e->getMessage());

            return to_route('admin.comunicazioni.index')->with(
                $this->flashError(__('comunicazioni.error_update_communication'))
            );

        }

        /*
         * Gli avvisi stanno **fuori dalla transazione**, come già fa `store()`: una mail che non
         * parte non deve far tornare indietro una modifica che il database ha accettato.
         */
        try {

            $this->avvisaDopoLaModifica($comunicazione, $destinatariPrima, $validated);

        } catch (\Exception $emailException) {

            Log::error('Error notifying update for comunicazione ID ' . $comunicazione->id . ': ' . $emailException->getMessage());

            return to_route('admin.comunicazioni.index')->with(
                $this->flashWarning(__('comunicazioni.error_notify_updated_communication'))
            );

        }

        $comunicazione->refresh();

        if ($comunicazione->programmata_per !== null) {
            $locale = $comunicazione->programmata_per->copy()->setTimezone(PubblicazioneProgrammata::fuso());

            return to_route('admin.comunicazioni.index')->with(
                $this->flashSuccess(__('comunicazioni.success_schedule_communication', [
                    'date' => $locale->format('d/m/Y'),
                    'time' => $locale->format('H:i'),
                ]))
            );
        }

        return to_route('admin.comunicazioni.index')->with(
            $this->flashSuccess(__('comunicazioni.success_update_communication'))
        );
    }

    /**
     * Chi va avvisato dopo una modifica, e con quale dei due avvisi.
     *
     * - **I nuovi arrivati** ricevono l'avviso di *creazione*, sempre e senza chiedere: per loro
     *   l'oggetto è nuovo davvero, e non avvisarli è il difetto che questa beta corregge.
     * - **Chi c'era già** riceve l'avviso di *modifica*, e **solo se l'amministratore lo chiede**.
     *   Mandare una mail a tutto il condominio perché si è corretto un refuso sarebbe la prima
     *   lamentela: l'ultima parola resta a chi firma la comunicazione.
     * - **L'autore** non è mai un nuovo arrivato: la comunicazione l'ha scritta lui. Entra nella platea
     *   quando la modifica la sposta nel suo palazzo o pubblica una bozza già approvata, e lì non
     *   riceve l'annuncio della propria; come all'approvazione, dove riceve «approvata» e non «nuova»
     *   (`SendApprovedComunicazioneNotificationToUser`).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $destinatariPrima
     * @param  array<string, mixed>  $validated
     */
    private function avvisaDopoLaModifica(Comunicazione $comunicazione, $destinatariPrima, array $validated): void
    {
        $destinatariDopo = app(DestinatariNotifica::class)->perModello($comunicazione->fresh());

        $dellAutore = $comunicazione->created_by === null
            ? collect()
            : Anagrafica::where('user_id', $comunicazione->created_by)->pluck('id');

        $nuovi = $destinatariDopo->diff($destinatariPrima)->diff($dellAutore)->values()->all();

        if ($nuovi !== []) {
            DestinatariDaAvvisare::dispatch($comunicazione, $nuovi, 'nuovo');
        }

        if (! ($validated['avvisa_destinatari'] ?? false)) {
            return;
        }

        $giaDestinatari = $destinatariDopo->intersect($destinatariPrima)->values()->all();

        if ($giaDestinatari !== []) {
            DestinatariDaAvvisare::dispatch($comunicazione, $giaDestinatari, 'aggiornato');
        }
    }

    /**
     * Remove the specified Comunicazione from storage.
     *
     * Authorizes the user to delete the comunicazione and performs the deletion. If an error occurs during deletion,
     * a log entry is created, and the user is notified.
     *
     * @param  \App\Models\Comunicazione $comunicazione
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the comunicazione
     * @throws \Exception If deletion fails
     */
    public function destroy(Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('delete', $comunicazione);

        try {

            $comunicazione->delete();

            return back()->with(
                $this->flashSuccess(__('comunicazioni.success_delete_communication'))
            );
            
        } catch (\Exception $e) {
            
            Log::error('Error deleting comunicazione: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('comunicazioni.error_delete_communication'))
            );
        }

    }

}
