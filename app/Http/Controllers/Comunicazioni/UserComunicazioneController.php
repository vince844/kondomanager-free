<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Traits\HasAnagrafica;
use App\Http\Requests\Comunicazione\ComunicazioneIndexRequest;
use App\Http\Requests\Comunicazione\CreateUserComunicazioneRequest;
use App\Http\Requests\Comunicazione\UpdateUserComunicazioneRequest;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Models\Comunicazione;
use App\Services\ComunicazioneService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;

class UserComunicazioneController extends Controller
{
    use HasAnagrafica;
    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ComunicazioneService 
     */
    public function __construct(
        private ComunicazioneService $comunicazioneService
    ) {}

    /**
     * Display a listing of the user's comunicazioni.
     *
     * This method handles the process of fetching the authenticated user's comunicazioni based on their `anagrafica` and related `condomini`. 
     * It includes authorization checks and filters the results based on the validated request data.
     * If an error occurs while retrieving the data, it logs the error and aborts with a 500 status.
     *
     * @param \App\Http\Requests\ComunicazioneIndexRequest $request The validated request data.
     * @param \App\Models\Comunicazione $comunicazione The comunicazione model used for authorization.
     * @return \Inertia\Response The Inertia response containing the view and paginated comunicazioni data.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view the comunicazioni.
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user does not have a valid anagrafica.
     * @throws \Exception If an unexpected error occurs while fetching comunicazioni.
     */
    public function index(ComunicazioneIndexRequest $request, Comunicazione $comunicazione): Response
    {
        Gate::authorize('view', $comunicazione);

        $validated = $request->validated();
    
        try {

            $user = Auth::user();

            if (!$user?->anagrafica) {
                abort(403, __('auth.not_authenticated'));
            } 

            // Get user anagrafica
            $anagrafica = $user->anagrafica;
            // Fetch the related condominio IDs
            $condominioIds = $anagrafica->condomini->pluck('id');
            // Get filtered segnalazioni from the service
            $comunicazioni = $this->comunicazioneService->getComunicazioni(
                anagrafica: $anagrafica,
                condominioIds: $condominioIds,
                validated: $validated
            );

        } catch (\Exception $e) {

            Log::error('Error getting user comunicazioni: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');

        }

        return Inertia::render('comunicazioni/user/UserComunicazioniList', [
            'comunicazioni' => [
                'data' => ComunicazioneResource::collection($comunicazioni)->resolve(),
                'current_page' => $comunicazioni->currentPage(),
                'last_page' => $comunicazioni->lastPage(),
                'per_page' => $comunicazioni->perPage(),
                'total' => $comunicazioni->total(),
            ],
            'search' => $validated['search'] ?? '',
            'filters' => Arr::only($validated, ['subject', 'priority', 'stato'])
        ]);
    }

    /**
     * Show the form for creating a new Comunicazione.
     *
     * Authorizes the user via policy, fetches the user's anagrafica and
     * the associated condomini (buildings), and passes them to the Inertia
     * frontend as props for the Comunicazioni creation page.
     *
     * @param  \App\Models\Comunicazione  $comunicazione  The Comunicazione instance used for authorization.
     * @return \Symfony\Component\HttpFoundation\Response  The Inertia response rendering the creation view.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to create a comunicazione.
     */
    public function create(Comunicazione $comunicazione): Response
    {
        Gate::authorize('create', $comunicazione);

        $anagrafica = $this->getUserAnagrafica();
        $condomini = $anagrafica->condomini;

        return Inertia::render('comunicazioni/user/ComunicazioniNew',[
            'condomini' => CondominioOptionsResource::collection($condomini)->resolve(),
        ]);  
    }

    /**
     * Store a newly created user Comunicazione in storage.
     *
     * Validates the incoming request, authorizes creation, creates the Comunicazione,
     * and associates it with selected condomini and (if private) the user's anagrafica.
     * Wraps the operation in a database transaction and handles any exceptions gracefully.
     *
     * @param  \App\Http\Requests\User\CreateUserComunicazioneRequest  $request  The request containing validated data for the comunicazione.
     * @param  \App\Models\Comunicazione  $comunicazione  The Comunicazione model instance (used for authorization).
     * @return \Illuminate\Http\RedirectResponse  Redirects to the comunicazioni index with a success or error flash message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to create the comunicazione.
     * @throws \Throwable  If an exception occurs during the transaction.
     */
    public function store(CreateUserComunicazioneRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('create', $comunicazione);

        $validated = $request->validated();

        try {

            DB::beginTransaction();

            $comunicazione = Comunicazione::create($validated);

            $comunicazione->condomini()->attach($validated['condomini_ids']);

            if ($validated['is_private']) {
                
                $anagrafica = $this->getUserAnagrafica();
                $comunicazione->anagrafiche()->attach($anagrafica);
            }

            DB::commit();

       /*      $this->notificationService->sendUserNotifications(
                validated: $validated,
                comunicazione: $comunicazione
            ); */

            return to_route('user.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La nuova comunicazione è stata creata con successo!"
                ]
            ]);

        } catch (\Exception $e) {
        
            DB::rollback();

            Log::error('Error creating user comunicazione: ' . $e->getMessage());

            return to_route('user.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante la creazione della comunicazione!"
                ]
            ]);

        }

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

        return Inertia::render('comunicazioni/ComunicazioniView', [
            'comunicazione' => new ComunicazioneResource(
                $comunicazione->loadMissing('createdBy')
            ),
        ]);
    }

    /**
     * Show the form for editing the specified comunicazione.
     *
     * This method ensures the authenticated user is authorized to update the
     * provided Comunicazione. It loads related data such as the condomini
     * associated with the user's anagrafica, and the comunicazione's
     * creator and condomini. Then it returns the Inertia response with the
     * edit view and required props.
     *
     * @param  \App\Models\Comunicazione  $comunicazione  The comunicazione to be edited
     * @return \Illuminate\Http\Response
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update
     */
    public function edit(Comunicazione $comunicazione): Response
    {
        Gate::authorize('update', $comunicazione);

        $anagrafica = $this->getUserAnagrafica();
        // Fetch the related condomini
        $condomini = $anagrafica->condomini;

        $comunicazione->loadMissing(['createdBy.anagrafica', 'condomini']);

        return Inertia::render('comunicazioni/user/ComunicazioniEdit', [
         'comunicazione' => new ComunicazioneResource($comunicazione),
         'condomini'     => CondominioOptionsResource::collection($condomini)->resolve(),
        ]);
    }

    /**
     * Update the specified Comunicazione.
     *
     * This method performs the following:
     * - Authorizes the user for the update action.
     * - Validates the incoming request.
     * - Updates the comunicazione record.
     * - Syncs associated condomini.
     * - Updates the relationship with the user's anagrafica based on the `is_private` flag.
     * 
     * All actions are wrapped in a database transaction. If any step fails, the transaction is rolled back,
     * the error is logged, and the user is redirected with an error message.
     *
     * @param  \App\Http\Requests\Comunicazione\UpdateUserComunicazioneRequest  $request  The validated update request.
     * @param  \App\Models\Comunicazione  $comunicazione  The Comunicazione instance to update.
     * @return \Illuminate\Http\RedirectResponse  Redirects to the comunicazioni index with a flash message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update.
     * @throws \Throwable If any part of the transaction fails.
     */
    public function update(UpdateUserComunicazioneRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('update', $comunicazione);

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $comunicazione->update($validated);

            $comunicazione->condomini()->sync($validated['condomini_ids']);

            $anagrafica = $this->getUserAnagrafica();

            if ($validated['is_private']) {
               
                $comunicazione->anagrafiche()->syncWithoutDetaching([$anagrafica->id]);
            }else {
                // Detach anagrafica if it was previously attached
                $comunicazione->anagrafiche()->detach($anagrafica->id);
            }

            DB::commit();

            return to_route('user.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La comunicazione è stata aggiornata con successo!"
                ]
            ]);

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating user comunicazione: ' . $e->getMessage());

            return to_route('user.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento della comunicazione!"
                ]
            ]);
        }
    }

    /**
     * Remove the specified Comunicazione from storage.
     *
     * Authorizes the user via policy, attempts to delete the comunicazione,
     * and redirects back with a success or error message depending on the outcome.
     * Logs any exception that occurs during deletion.
     *
     * @param  \App\Models\Comunicazione  $comunicazione  The Comunicazione instance to be deleted.
     * @return \Illuminate\Http\RedirectResponse  Redirects back with a flash message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to delete the comunicazione.
     */
    public function destroy(Comunicazione $comunicazione): RedirectResponse
    {

        Gate::authorize('delete', $comunicazione);

        try {

            $comunicazione->delete();

            return back()->with([
                'message' => [ 
                    'type'    => 'success',
                    'message' => "La comunicazione è stata eliminata con successo"
                ]
            ]);

        } catch (\Exception $e) {
            
            Log::error('Error deleting comunicazione: ' . $e->getMessage());

            return back()->with([
                'message' => [ 
                    'type'    => 'error',
                    'message' => "Si è verificato un errore nel tentativo di eliminare la comunicazione"
                ]
            ]);
        }

    }

}
