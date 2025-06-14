<?php

namespace App\Http\Controllers\Documenti\Utenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Models\CategoriaDocumento;
use App\Services\DocumentoService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Auth;

class CategoriaDocumentoController extends Controller
{
    /**
     * Inject the SegnalazioneService.
     *
     * @param  \App\Services\DocoumentoService $documentoService
     */
    public function __construct(
        private DocumentoService $documentoService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $user = Auth::user();

        $anagrafica = $user->anagrafica;
        // Fetch the related condominio IDs
        $condominioIds = $anagrafica->condomini->pluck('id');

        // Fetch the documenti using the DocumentoService
        $documenti = $this->documentoService->getDocumenti(
            anagrafica: $anagrafica,
            condominioIds: $condominioIds,
            validated: []
        );

        /** @var \Illuminate\Pagination\LengthAwarePaginator $documenti */
        $documentiLimited = $documenti->take(3);
       
        return Inertia::render('documenti/user/CategorieList', [
            'categorie' => CategoriaDocumento::withCount('documenti')->get(),
            'documenti' => DocumentoResource::collection($documentiLimited),
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(CategoriaDocumento $categoriaDocumento): Response
    {
  
        $categoriaDocumento->load('documenti', 'documenti.createdBy');

        return Inertia::render('documenti/user/DocumentiList', [
            'categoria' => new CategoriaDocumentoResource($categoriaDocumento),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(CategoriaDocumento $categoriaDocumento)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CategoriaDocumento $categoriaDocumento)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CategoriaDocumento $categoriaDocumento)
    {
        //
    }
}
