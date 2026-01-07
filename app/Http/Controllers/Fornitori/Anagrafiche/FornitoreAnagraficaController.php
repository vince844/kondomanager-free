<?php

namespace App\Http\Controllers\Fornitori\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fornitore\Anagrafica\CreateFornitoreAnagraficaRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Models\Anagrafica;
use App\Models\Fornitore;
use App\Traits\HandleFlashMessages;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

/**
 * Controller for managing anagrafiche (contacts) associated with suppliers.
 *
 * This controller handles CRUD operations for the relationships between suppliers
 * and anagrafiche (contacts/references), allowing to associate, view and remove
 * contacts and references from suppliers.
 */
class FornitoreAnagraficaController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of anagrafiche associated with a supplier.
     *
     * Loads all references (anagrafiche) linked to the specified supplier
     * through the many-to-many relationship in the anagrafica_fornitore pivot table.
     *
     * @param Fornitore $fornitore The supplier whose references to display
     * @return Response The Inertia view with the list of anagrafiche
     * @since v1.8.0
     */
    public function index(Fornitore $fornitore): Response
    {
        $fornitore->loadMissing(['referenti' => function($query) use ($fornitore) {
            $query->where('fornitore_id', $fornitore->id);
        }]);

        return Inertia::render('fornitori/anagrafiche/AnagraficheList', [
            'fornitore' => $fornitore,
        ]);
    }

    /**
     * Show the form for associating a new anagrafica with the supplier.
     *
     * Retrieves all anagrafiche that are NOT yet associated with the current
     * supplier, allowing the user to select and associate new references.
     *
     * @param Fornitore $fornitore The supplier to associate the anagrafica with
     * @return Response The Inertia view with the creation form
     * @since v1.8.0
     */
    public function create(Fornitore $fornitore): Response
    {
        // Get all anagrafiche that are NOT associated with this supplier
        $availableAnagrafiche = Anagrafica::whereDoesntHave('fornitori', function($query) use ($fornitore) {
            $query->where('fornitore_id', $fornitore->id);
        })->get();

        return Inertia::render('fornitori/anagrafiche/AnagraficheNew', [
            'fornitore'   => $fornitore,
            'anagrafiche' => AnagraficaResource::collection($availableAnagrafiche),
        ]);
    }

    /**
     * Associate a new anagrafica with the supplier.
     *
     * Creates a relationship in the anagrafica_fornitore pivot table between the
     * supplier and the selected anagrafica, including the role specified by the user.
     *
     * @param CreateFornitoreAnagraficaRequest $request The validated request data
     * @param Fornitore $fornitore The supplier to associate the anagrafica with
     * @return RedirectResponse Redirect to the list with success or error message
     * @since v1.8.0
     */
    public function store(CreateFornitoreAnagraficaRequest $request, Fornitore $fornitore): RedirectResponse
    {
        $data = $request->validated();

        try {
            
            $fornitore->referenti()->attach($data['anagrafica_id'], [
                'ruolo' => $data['ruolo']
            ]);

            return to_route('admin.fornitori.anagrafiche.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashSuccess(__('fornitori.success_attach_anagrafica')));

        } catch (\Throwable $e) {

            Log::error('Error attaching anagrafica to fornitore', [
                'fornitore' => $fornitore->id,
                'error'     => $e->getMessage(),
            ]);

            return to_route('admin.fornitori.anagrafiche.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashError(__('fornitori.error_attach_anagrafica')));
        }
    }

    /**
     * Remove the association between an anagrafica and a supplier.
     *
     * Deletes the relationship in the anagrafica_fornitore pivot table, removing
     * the reference from the supplier without deleting either the anagrafica or the supplier.
     *
     * @param Fornitore $fornitore The supplier to remove the anagrafica from
     * @param Anagrafica $anagrafica The anagrafica to dissociate
     * @return RedirectResponse Redirect to the list with success or error message
     * @since v1.8.0
     */
    public function destroy(Fornitore $fornitore, Anagrafica $anagrafica): RedirectResponse
    {
        try {

            $fornitore->referenti()->detach($anagrafica->id);

            return to_route('admin.fornitori.anagrafiche.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashSuccess(__('fornitori.success_detach_anagrafica')));

        } catch (\Throwable $e) {

            Log::error('Error detaching anagrafica from fornitore', [
                'fornitore'  => $fornitore->id,
                'anagrafica' => $anagrafica->id,
                'error'      => $e->getMessage(),
            ]);

            return to_route('admin.fornitori.anagrafiche.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashError(__('fornitori.error_detach_anagrafica')));
        }
    }
}