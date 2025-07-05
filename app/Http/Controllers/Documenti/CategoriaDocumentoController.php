<?php

namespace App\Http\Controllers\Documenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documento\Categoria\CategoriaDocumentoIndexRequest;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Models\CategoriaDocumento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Arr;

class CategoriaDocumentoController extends Controller
{
    use HandleFlashMessages;
    /**
     * Display a listing of the resource.
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
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'required|string|max:255',
        ]);

        $categoria = CategoriaDocumento::create($validated);

        return response()->json($categoria); // for dynamic use in Vue
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CategoriaDocumento $categoria)
    {
        
        if ($categoria->documenti()->exists()) {

            return redirect()->back()->with(
                $this->flashInfo(__('documenti.category_has_documents'))
            );
        }

        $categoria->delete();

        return redirect()->back()->with(
            $this->flashSuccess(__('documenti.success_delete_category'))
        );

    }
}
