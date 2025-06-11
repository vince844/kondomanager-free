<?php

namespace App\Http\Controllers\Documenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documento\CreateDocumentoRequest;
use App\Http\Requests\Documento\DocumentoIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Services\DocumentoService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

class DocumentoController extends Controller
{
    use HandleFlashMessages;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\DocumentoService 
     */
    public function __construct(
        private DocumentoService $documentoService,
    ) {}
    
    /**
     * Display a listing of the resource.
     */
    public function index(DocumentoIndexRequest $request, Documento $documento): Response
    {
        $validated = $request->validated();

        $documenti = $this->documentoService->getDocumenti(  
            anagrafica: null,
            condominioIds: null,
            validated: $validated
        );

        return Inertia::render('documenti/DocumentiList', [
            'documenti' => DocumentoResource::collection($documenti)->resolve(),
            'meta' => [
                'current_page' => $documenti->currentPage(),
                'last_page'    => $documenti->lastPage(),
                'per_page'     => $documenti->perPage(),
                'total'        => $documenti->total(),
            ],
            'filters' => Arr::only($validated, ['name'])
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
       return Inertia::render('documenti/DocumentiNew', [
            'categories' => CategoriaDocumentoResource::collection(CategoriaDocumento::all()),
            'condomini'  => CondominioResource::collection(Condominio::all()),
            'anagrafiche' => []
        ]); 
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateDocumentoRequest $request)
    {

        $validated = $request->validated();

        /** @var \Illuminate\Http\Request $request */
        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return to_route('admin.documenti.index')->with(
                $this->flashError(__('documenti.no_file_uploaded'))
            );
        }

        try {

            DB::beginTransaction();

            /** @var \Illuminate\Http\Request $request */
            $uploadedFile = $request->file('file');

            $path = $uploadedFile->storeAs('documenti', $uploadedFile->hashName());

            $documento = Documento::create([
                'name'         => $validated['name'],
                'description'  => $validated['description'],
                'path'         => $path,
                'mime_type'    => $uploadedFile->getClientMimeType(),
                'file_size'    => $uploadedFile->getSize(),
                'created_by'   => $validated['created_by'],
                'category_id'  => $validated['category_id'],
                'is_published' => $validated['is_published'],
                'is_approved'  => $validated['is_approved'],
            ]);

            $documento->condomini()->attach($validated['condomini_ids']);

            if (!empty($validated['anagrafiche'])) {
                $documento->anagrafiche()->attach($validated['anagrafiche']);
            }

            DB::commit();

        } catch (\Exception $e) {
            
            DB::rollback();
            
            Log::error('Error creating documento archivio: ' . $e->getMessage());
        
            return to_route('admin.documenti.index')->with(
                $this->flashError(__('documenti.error_create_document'))
            );

        }

        return to_route('admin.documenti.index')->with(
            $this->flashSuccess(__('documenti.success_create_document'))
        );

    }

    /**
     * Display the specified resource.
     */
    public function show(Documento $documento)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Documento $documento)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Documento $documento)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Documento $documento)
    {
        //
    }
}
