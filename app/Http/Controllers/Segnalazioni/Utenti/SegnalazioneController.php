<?php

namespace App\Http\Controllers\Segnalazioni\Utenti;

use App\Events\Segnalazioni\NotifyAdminOfCreatedSegnalazione;
use App\Http\Controllers\Controller;
use App\Http\Requests\Segnalazione\SegnalazioneIndexRequest;
use App\Http\Requests\Segnalazione\UserCreateSegnalazioneRequest;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Segnalazione;
use App\Services\SegnalazioneService;
use App\Traits\HandleFlashMessages;
use App\Traits\HandlesUserCondominioData;
use App\Traits\HasAnagrafica;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class SegnalazioneController extends Controller
{
    use HasAnagrafica, HandleFlashMessages, HandlesUserCondominioData;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\SegnalazioneService 
     */
    public function __construct(
        private SegnalazioneService $segnalazioneService,
    ) {}
    
    /**
     * Display a listing of segnalazioni based on the authenticated user's anagrafica and related condomini.
     *
     * This method retrieves the list of segnalazioni filtered by the user’s anagrafica and related condominio IDs.
     * It also ensures the user is authenticated, and their anagrafica exists before proceeding. If any error occurs 
     * during the fetching process, it logs the error and aborts with a 500 error.
     *
     * @param  \App\Http\Requests\SegnalazioneIndexRequest  $request  The request object containing the validated filter parameters.
     * @param  \App\Models\Segnalazione  $segnalazione  The segnalazione model used for authorization.
     * @return \Inertia\Response  Returns an Inertia.js response rendering the filtered segnalazioni data along with pagination and filters.
     *
     * @throws \Exception  If an error occurs while fetching the user’s segnalazioni, an exception will be thrown and a 500 error will be triggered.
     */
    public function index(SegnalazioneIndexRequest $request, Segnalazione $segnalazione): Response
    {

        Gate::authorize('view', $segnalazione);  

        $validated = $request->validated();
    
        try {

            $userData = $this->getUserCondominioData();

            // Get filtered segnalazioni from the service
            $segnalazioni = $this->segnalazioneService->getSegnalazioni(
                anagrafica: $userData->anagrafica,
                condominioIds: $userData->condominioIds,
                validated: $validated
            );

            // Get stats using the same service
            $stats = $this->segnalazioneService->getSegnalazioniStats();

        } catch (\Exception $e) {

            Log::error('Error getting user segnalazioni: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');

        }

        return Inertia::render('segnalazioni/user/SegnalazioniList', [

            'segnalazioni' => [
                'data' => SegnalazioneResource::collection($segnalazioni)->resolve(),
                'current_page' => $segnalazioni->currentPage(),
                'last_page' => $segnalazioni->lastPage(),
                'per_page' => $segnalazioni->perPage(),
                'total' => $segnalazioni->total(),
            ],
            'stats' => $stats,
            'search' => $validated['search'] ?? null,
            'filters' => Arr::only($validated, ['subject', 'priority', 'stato'])
            
        ]);  

    } 

    /**
     * Show the form for creating a new segnalazione.
     *
     * This method checks if the user is authorized to create a new segnalazione. It also ensures that the user is authenticated 
     * and has an associated anagrafica. If the user is not authenticated or does not have an anagrafica, a 403 error is returned. 
     * The method retrieves the user's anagrafica and related condomini to pass them to the view for the creation of the new segnalazione.
     *
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione model used for authorization.
     * @return \Inertia\Response Returns an Inertia.js response to render the segnalazione creation page with available condomini options.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException  If the user is not authenticated or does not have an anagrafica, a 403 error is triggered.
     */
    public function create(Segnalazione $segnalazione): Response
    {
        Gate::authorize('create', $segnalazione);

        $anagrafica = $this->getUserAnagrafica();
        $condomini = $anagrafica->condomini;

        return Inertia::render('segnalazioni/user/SegnalazioniNew',[
            'condomini' => CondominioOptionsResource::collection($condomini)
        ]); 
    }

    /**
     * Store a newly created segnalazione in the database.
     *
     * This method handles the creation of a new segnalazione. It first checks if the user is authorized to create a segnalazione 
     * using the `Gate::authorize` method. Upon successful validation of the incoming request, the segnalazione is created and stored 
     * in the database. Additionally, notifications are sent to the admin about the new segnalazione.
     * If the segnalazione is approved, a success message is shown; if not, a message indicating the need for admin approval is displayed.
     * In case of an error, the process is logged, and an error message is shown.
     *
     * @param  \App\Http\Requests\UserCreateSegnalazioneRequest  $request  The validated request object containing the data for the new segnalazione.
     * @param  \App\Models\Segnalazione  $segnalazione  The segnalazione model used for authorization.
     * @return \Illuminate\Http\RedirectResponse Returns a redirect response to the user’s segnalazioni index route with a success or error message.
     *
     * @throws \Exception If an error occurs while creating the segnalazione, an exception will be caught, logged, and an error message will be shown.
     */
    public function store(UserCreateSegnalazioneRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
  
        Gate::authorize('create', $segnalazione);

        $validated = $request->validated();

        try {

            DB::beginTransaction();

            $segnalazione = Segnalazione::create($validated);

            if ($validated['is_private']) {
                $anagrafica = $this->getUserAnagrafica();
                $segnalazione->anagrafiche()->attach($anagrafica);
            }

            DB::commit();

            NotifyAdminOfCreatedSegnalazione::dispatch($segnalazione);

            $messageText = $segnalazione->is_approved
            ? __('segnalazioni.success_create_ticket')
            : __('segnalazioni.success_create_ticket_in_moderation');

            return to_route('user.segnalazioni.index')->with(
                $this->flashSuccess($messageText)
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error user creating user segnalazione: ' . $e->getMessage());

            return to_route('user.segnalazioni.index')->with(
                $this->flashError(__('segnalazioni.error_create_ticket'))
            );

        }

    }

    /**
     * Display the specified segnalazione.
     *
     * This method handles the display of a specific segnalazione. It first checks if the user is authorized to view the segnalazione
     * using `Gate::authorize`. Then, it loads the related relationships such as `createdBy`, `assignedTo`, `condominio`, and `anagrafiche`
     * to ensure that all necessary data is available for the view. Afterward, it renders the segnalazione view using Inertia.js, passing 
     * the segnalazione data wrapped in a resource.
     *
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione model to be displayed.
     * @return \Inertia\Response Returns an Inertia.js response to render the SegnalazioniView page with the specified segnalazione data.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to view the segnalazione, an authorization exception is thrown.
     */
    public function show(Segnalazione $segnalazione): Response
    {
        Gate::authorize('show', $segnalazione);

        return Inertia::render('segnalazioni/SegnalazioniView', [
            'segnalazione' => new SegnalazioneResource(
                Segnalazione::with('createdBy.anagrafica',  'assignedTo', 'condominio', 'anagrafiche')->findOrFail($segnalazione->id)
            ),
        ]);
    }

    /**
     * Show the form for editing the specified segnalazione.
     *
     * This method handles the process of displaying the edit form for a specific segnalazione. It first checks if the user is authorized
     * to update the segnalazione using `Gate::authorize`. Then, it ensures the user is authenticated and has an associated `anagrafica`.
     * After that, it fetches the related condomini for the user's anagrafica. The segnalazione is loaded with necessary relationships such 
     * as `createdBy`, `assignedTo`, `condominio`, and `anagrafiche` to provide all required data for editing.
     * Finally, it renders the edit form using Inertia.js, passing the segnalazione data and available condomini for selection in the view.
     *
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione model to be edited.
     * @return \Inertia\Response Returns an Inertia.js response to render the UserSegnalazioniEdit page with the segnalazione data and available condomini.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to update the segnalazione, an authorization exception is thrown.
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException  If the user is not authenticated or does not have an associated anagrafica.
     */
    public function edit(Segnalazione $segnalazione): Response
    {
        Gate::authorize('update', $segnalazione);
        
        $anagrafica = $this->getUserAnagrafica();
        $condomini = $anagrafica->condomini;
        
        $segnalazione->loadMissing(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/user/SegnalazioniEdit', [
            'segnalazione'  => new SegnalazioneResource($segnalazione),
            'condomini'     => CondominioOptionsResource::collection($condomini)
        ]);
    }

    /**
     * Update the specified segnalazione in storage.
     *
     * This method handles the update operation for an existing segnalazione. It first ensures the user is authorized to update the segnalazione
     * using `Gate::authorize`. After validating the request data, it attempts to update the segnalazione with the provided information. 
     * If successful, a success message is flashed and the user is redirected to the index page. If any error occurs during the update process,
     * the transaction is caught, and an error message is logged while the user is redirected with an error notification.
     *
     * @param  \App\Http\Requests\UserCreateSegnalazioneRequest $request  The request containing validated data for updating the segnalazione.
     * @param  \App\Models\Segnalazione  $segnalazione The segnalazione model to be updated.
     * @return \Illuminate\Http\RedirectResponse Returns a redirect response to the user.segnalazioni.index route with a success or error message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the segnalazione, an authorization exception is thrown.
     * @throws \Exception If an error occurs during the update process, an exception is thrown and logged.
     */
    public function update(UserCreateSegnalazioneRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('update', $segnalazione);

        $validated = $request->validated(); 

        try {

            $segnalazione->update($validated);

            return to_route('user.segnalazioni.index')->with(
                $this->flashSuccess(__('segnalazioni.success_update_ticket'))
            );

        } catch (\Exception $e) {

            Log::error('Error user updating segnalazione: ' . $e->getMessage());

            return to_route('user.segnalazioni.index')->with(
                $this->flashError(__('segnalazioni.error_update_ticket'))
            );

        }
    }

    /**
     * Elimina una segnalazione specifica se l'utente ha l'autorizzazione.
     *
     * Questa funzione verifica i permessi tramite il Gate 'delete', tenta di eliminare
     * la segnalazione dal database e restituisce una risposta di redirect con un messaggio
     * di successo o di errore, a seconda dell'esito dell'operazione.
     *
     * @param  \App\Models\Segnalazione $segnalazione  L'istanza della segnalazione da eliminare
     * @return \Illuminate\Http\RedirectResponse Risposta con redirect alla pagina precedente, con messaggio flash
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Se l'utente non è autorizzato
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
            
            Log::error('Error user deleting segnalazione: ' . $e->getMessage());

             return back()->with(
                $this->flashError(__('segnalazioni.error_delete_ticket'))
            );

        }
    }
}
