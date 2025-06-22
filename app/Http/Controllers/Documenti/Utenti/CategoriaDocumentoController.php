<?php

namespace App\Http\Controllers\Documenti\Utenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documento\Categoria\CategoriaDocumentoIndexRequest;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Models\CategoriaDocumento;
use App\Services\DocumentoService;
use App\Traits\HandlesUserCondominioData;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Arr;

class CategoriaDocumentoController extends Controller
{
    use HandlesUserCondominioData;

    public function __construct(
        private DocumentoService $documentoService
    ) {}

    public function index(): Response
    {
        $userData = $this->getUserCondominioData();

        $allCategorie = CategoriaDocumento::orderBy('name')->get();
        
        $userDocCounts = $this->documentoService->getUserDocumentCountsByCategoria(
            $userData->anagrafica,
            $userData->condominioIds
        );

        $categorie = $allCategorie->map(function ($categoria) use ($userDocCounts) {
            return new CategoriaDocumentoResource(
                $categoria->setRelation('documenti_count', $userDocCounts[$categoria->id] ?? 0)
            );
        });

        $documenti = $this->documentoService->getDocumenti(
            anagrafica: $userData->anagrafica,
            condominioIds: $userData->condominioIds,
            validated: [],
            limit: 3
        );

        return Inertia::render('documenti/user/CategorieList', [
            'categorie' => $categorie,
            'documenti' => DocumentoResource::collection($documenti),
        ]);
    }

    public function show(CategoriaDocumentoIndexRequest $request, CategoriaDocumento $categoriaDocumento): Response
    {
        Gate::authorize('view', $categoriaDocumento);

        $userData = $this->getUserCondominioData();
        $validated = $request->validated();

        $documenti = $this->documentoService->getDocumentiByCategoria(
            anagrafica: $userData->anagrafica,
            condominioIds: $userData->condominioIds,
            categoriaId: $categoriaDocumento->id,
            validated: $validated
        );

        return Inertia::render('documenti/user/DocumentiList', [
            'documenti' => [
                'data' => DocumentoResource::collection($documenti)->resolve(),
                'current_page' => $documenti->currentPage(),
                'last_page' => $documenti->lastPage(),
                'per_page' => $documenti->perPage(),
                'total' => $documenti->total(),
            ],
            'categoria' => new CategoriaDocumentoResource($categoriaDocumento),
            'search' => $validated['search'] ?? null, 
            'filters' => Arr::only($validated, ['name'])
        ]);

    }
}