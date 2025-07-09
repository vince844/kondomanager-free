<?php

namespace App\Http\Controllers\Documenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documento\Categoria\CategoriaDocumentoIndexRequest;
use App\Http\Requests\Documento\Categoria\CreateCategoriaRequest;
use App\Http\Requests\Documento\Categoria\UpdateCategoriaRequest;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Models\CategoriaDocumento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class CategoriaDocumentoController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of the categoria documenti.
     *
     * Applies optional filters (e.g., by name), paginates the results, 
     * and returns the data to an Inertia view along with meta information and active filters.
     *
     * @param  \App\Http\Requests\Documento\Categoria\CategoriaDocumentoIndexRequest  $request
     * @return \Inertia\Response
     */
    public function index(CategoriaDocumentoIndexRequest $request): Response
    {

        $validated = $request->validated();

        $query = CategoriaDocumento::query();

        // Apply filters if present
        if (!empty($validated['name'])) {
            $query->where('name', 'like', '%' . $validated['name'] . '%');
        }

        // Paginate the result
        $categorie = $query->paginate(config('pagination.default_per_page'))->withQueryString();

        return Inertia::render('documenti/categories/CategorieList', [
            'categorie' => CategoriaDocumentoResource::collection($categorie)->resolve(), 
            'meta' => [
                'current_page' => $categorie->currentPage(),
                'last_page'    => $categorie->lastPage(),
                'per_page'     => $categorie->perPage(),
                'total'        => $categorie->total(),
            ],
            'filters' => Arr::only($validated, ['name'])
        ]);
    }

    /**
     * Store a newly created categoria.
     *
     * @param  \App\Http\Requests\Documento\Categoria\CreateCategoriaRequest $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(CreateCategoriaRequest $request)
    {   
        try {

            $validated = $request->validated();

            $categoria = CategoriaDocumento::create($validated);

            // For form submission via Axios
            if ($request->wantsJson()) {
                return response()->json($categoria);
            }

            // For regular form submission via Inertia
            return redirect()->back()->with(
                $this->flashSuccess(__('documenti.success_create_category'))
            );

        } catch (\Exception $e) {

            // Log error or customize response
            Log::error('Category creation failed: ' . $e->getMessage());

            if ($request->wantsJson()) {
                return response()->json(['error' => __('documenti.error_create_category')], 500);
            }

            return redirect()->back()->with(
                $this->flashError(__('documenti.error_create_category'))
            );

        }
       
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoriaRequest $request, CategoriaDocumento $categoria): RedirectResponse
    {

        $validated = $request->validated(); 

        try {

            $categoria->update($validated);

            return redirect()->back()->with(
                $this->flashSuccess(__('documenti.success_update_category'))
            );


        } catch (\Exception $e) {

            Log::error('Errore durante l\'aggiornamento della categoria: ' . $e->getMessage());

            return redirect()->back()->with(
                $this->flashError(__('documenti.error_update_category'))
            );
        }
   
    }

    /**
     * Remove the specified categoria.
     *
     * @param  \App\Models\Documento\CategoriaDocumento $categoria
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(CategoriaDocumento $categoria)
    {
        try {
            
            // Check if category has document and avoid delete
            if ($categoria->documenti()->exists()) {
                return redirect()->back()->with(
                    $this->flashInfo(__('documenti.category_has_documents'))
                );
            }

            $categoria->delete();

            return redirect()->back()->with(
                $this->flashSuccess(__('documenti.success_delete_category'))
            );

        } catch (\Exception $e) {

            Log::error('Errore durante l\'eliminazione della categoria: ' . $e->getMessage());

            return redirect()->back()->with(
                $this->flashError(__('documenti.error_delete_category'))
            );
        }
    }
}
