<?php

namespace App\Http\Controllers\Fornitori;

use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fornitore\CreateFornitoreRequest;
use App\Http\Requests\Fornitore\FornitoreIndexRequest;
use App\Http\Requests\Fornitore\UpdateFornitoreRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Fornitore\Categorie\CategoriaFornitoreResource;
use App\Http\Resources\Fornitore\EditFornitoreResource;
use App\Http\Resources\Fornitore\FornitoreResource;
use App\Models\Anagrafica;
use App\Models\CategoriaFornitore;
use App\Models\Fornitore;
use App\Traits\HandleFlashMessages;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FornitoreController extends Controller
{
    use HandleFlashMessages;
    
    /**
     * Display paginated list of fornitori with filtering options.
     * 
     * Returns fornitori data for the index page with optional ragione_sociale filter.
     * Includes pagination metadata and current filters for the frontend.
     *
     * @param FornitoreIndexRequest $request Validated request with filters
     * @return Response Inertia response with fornitori data and pagination info
     * @since v1.8.0
     */
    public function index(FornitoreIndexRequest $request): Response
    {
        $validated = $request->validated();

        $fornitori = Fornitore::with(['referenti:id,nome,indirizzo', 'categoria'])
            ->when($validated['ragione_sociale'] ?? false, function ($query, $ragioneSociale) {
                $query->where('ragione_sociale', 'like', "%{$ragioneSociale}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->withQueryString();
    
        return Inertia::render('fornitori/FornitoriList', [
            'fornitori' => FornitoreResource::collection($fornitori)->resolve(),
            'meta' => [
                'current_page' => $fornitori->currentPage(),
                'last_page'    => $fornitori->lastPage(),
                'per_page'     => $fornitori->perPage(),
                'total'        => $fornitori->total(),
            ],
            'filters' => $request->only(['ragione_sociale']) 
        ]);
    }

    /**
     * Show the form for creating a new fornitore.
     * Returns an Inertia response with data needed for the create form.
     *
     * @return Response
     * @since v1.8.0
     */
    public function create(): Response
    {
        return Inertia::render('fornitori/FornitoriNew', [
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all()),
            'categorie'   => CategoriaFornitoreResource::collection(CategoriaFornitore::all()),
        ]);
    }

    /**
     * Store a newly created fornitore in storage.
     * Creates a new fornitore record and attaches related referenti.
     *
     * @param CreateFornitoreRequest $request Validated request data
     * @param Fornitore $fornitore Fornitore model instance
     * @return RedirectResponse Redirects to index with success/error message
     * @since v1.8.0
     */
    public function store(CreateFornitoreRequest $request, Fornitore $fornitore): RedirectResponse
    {

        $data = $request->validated();

        try {

            DB::beginTransaction();

            $fornitore = Fornitore::create($data);

            $fornitore->referenti()->attach($data['anagrafica_id']);

            DB::commit();

            return to_route('admin.fornitori.index')->with(
                $this->flashSuccess(__('fornitori.success_create_fornitore'))
            );

        } catch (\Throwable $e) {

            DB::rollback();
            
            Log::error('Error creating fornitore', [
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.fornitori.index')->with(
                $this->flashError(__('fornitori.error_create_fornitore'))
            );

        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Fornitore $fornitore): Response
    {

        $fornitore->loadMissing('referenti', 'categoria');

        return Inertia::render('fornitori/FornitoriView', [
            'fornitore' => new FornitoreResource($fornitore),
        ]);
     
    }

    /**
     * Show the form for editing the specified fornitore.
     * Returns data needed for the edit form including related categories.
     *
     * @param Fornitore $fornitore Fornitore to edit
     * @return Response
     * @since v1.8.0
     */
    public function edit(Fornitore $fornitore): Response
    {
        RedirectHelper::rememberUrl();

        return Inertia::render('fornitori/FornitoriEdit', [
            'fornitore'   => new EditFornitoreResource($fornitore),
            'categorie'   => CategoriaFornitoreResource::collection(CategoriaFornitore::all())
       ]);
    }

    /**
     * Update the specified fornitore in storage.
     * Updates fornitore data and redirects with success/error message.
     *
     * @param UpdateFornitoreRequest $request Validated request data
     * @param Fornitore $fornitore Fornitore to update
     * @return RedirectResponse Redirects to index with message
     * @since v1.8.0
     */
    public function update(UpdateFornitoreRequest $request, Fornitore $fornitore): RedirectResponse
    {
        $validated = $request->validated(); 

         try {

            $fornitore->update($validated);

            return RedirectHelper::backOr(
                route('admin.fornitori.index'),
                $this->flashSuccess(__('fornitori.success_update_fornitore'))
            );

        } catch (\Exception $e) {

            Log::error('Error updating fornitore: ' . $e->getMessage());

             return RedirectHelper::backOr(
                route('admin.fornitori.index'),
                $this->flashError(__('fornitori.error_update_fornitore'))
            );
        }

    }

    /**
     * Remove the specified fornitore from storage.
     * Deletes a fornitore record and returns appropriate response.
     *
     * @param Fornitore $fornitore Fornitore to delete
     * @return RedirectResponse Redirects back with success/error message
     * @since v1.8.0
     */
    public function destroy(Fornitore $fornitore): RedirectResponse
    {
        try {

            $fornitore->delete();

            return back()->with(
                $this->flashSuccess(__('fornitori.success_delete_fornitore'))
            );

        } catch (\Exception $e) {

            Log::error('Error deleting fornitore: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('fornitori.error_delete_fornitore'))
            );

        }
    }
}
