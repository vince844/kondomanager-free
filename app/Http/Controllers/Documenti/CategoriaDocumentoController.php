<?php

namespace App\Http\Controllers\Documenti;

use App\Traits\OrdinaElenco;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documento\Categoria\CategoriaDocumentoIndexRequest;
use App\Http\Requests\Documento\Categoria\CreateCategoriaRequest;
use App\Http\Requests\Documento\Categoria\UpdateCategoriaRequest;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Models\CategoriaDocumento;
use App\Traits\HandleFlashMessages;
use App\Traits\PaginaElenco;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class CategoriaDocumentoController extends Controller
{
    use OrdinaElenco;
    use HandleFlashMessages;
    use PaginaElenco;

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

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        // ⚠️ **Il conteggio e le righe arrivano con l'elenco**, come nelle categorie di
        // fornitore: servono a dire «questa non si può eliminare» **prima** che l'utente ci provi,
        // e a dirgli **quali** documenti lo impediscono invece di lasciarlo a cercarli.
        $query = CategoriaDocumento::query()
            ->withCount('documenti')
            ->with(['documenti' => fn ($q) => $q->select('documenti.id', 'documenti.name')->orderBy('documenti.name')]);

        // Apply filters if present
        if (!empty($validated['name'])) {
            $query->where('name', 'like', '%' . $validated['name'] . '%');
        }

        // Paginate the result
        $categorie = $query->tap(fn ($q) => $this->ordina($q, $validated, CategoriaDocumentoIndexRequest::colonneOrdinabili(), predefinita: 'name', versoPredefinito: 'asc'))
            ->paginate($validated['per_page'])->withQueryString();

        return Inertia::render('documenti/categories/CategorieList', [
            'categorie' => $categorie->map(fn (CategoriaDocumento $c) => [
                'id'              => $c->id,
                'name'            => $c->name,
                'description'     => $c->description,
                'documenti_count' => $c->documenti_count,

                // La forma è quella che serve alla finestra «non si può eliminare»: nome e
                // collegamento all'archivio filtrato su quel documento.
                'documenti' => $c->documenti->map(fn ($d) => [
                    'id'   => $d->id,
                    'name' => $d->name,
                ])->values(),
            ])->values(),
            'meta' => [
                'current_page' => $categorie->currentPage(),
                'last_page'    => $categorie->lastPage(),
                'per_page'     => $categorie->perPage(),
                'total'        => $categorie->total(),
            ],
            'filters' => Arr::only($validated, ['name']),
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
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
