<?php

namespace App\Http\Controllers\Documenti;

use App\Events\Documenti\NotifyUserOfCreatedDocumento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documento\CreateDocumentoRequest;
use App\Http\Requests\Documento\DocumentoIndexRequest;
use App\Http\Requests\Documento\UpdateDocumentoRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Services\DocumentoService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

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
        Gate::authorize('view', $documento);

        $validated = $request->validated();

        $documenti = $this->documentoService->getDocumenti(  
            anagrafica: null,
            condominioIds: null,
            validated: $validated
        );

        // Get stats using the same service
        $stats = $this->documentoService->getDocumentiStats();

        return Inertia::render('documenti/DocumentiList', [
            'documenti' => DocumentoResource::collection($documenti)->resolve(),
            'stats' => $stats,
            'meta' => [
                'current_page' => $documenti->currentPage(),
                'last_page'    => $documenti->lastPage(),
                'per_page'     => $documenti->perPage(),
                'total'        => $documenti->total(),
            ],
            'filters' => Arr::only($validated, ['name', 'category_id'])
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Documento $documento): Response
    {
       Gate::authorize('create',$documento);

       return Inertia::render('documenti/DocumentiNew', [
            'categories' => CategoriaDocumentoResource::collection(CategoriaDocumento::all()),
            'condomini'  => CondominioResource::collection(Condominio::all()),
            'anagrafiche' => []
        ]); 
    }

    /**
     * Store a newly uploaded document in the database and filesystem.
     *
     * This method performs the following:
     * - Validates incoming form data via CreateDocumentoRequest.
     * - Verifies that a file was uploaded and is valid.
     * - Stores the uploaded file in the `storage/app/documenti` directory using a hashed name.
     * - Creates a new Documento record in the database.
     * - Attaches related condomini and anagrafiche records via pivot tables.
     * - Uses a database transaction to ensure consistency.
     * - Logs and handles any exceptions that may occur.
     *
     * @param  \App\Http\Requests\CreateDocumentoRequest  $request  The incoming HTTP request containing form data and file.
     * @return \Illuminate\Http\RedirectResponse  Redirects to the document index route with a success or error message.
     */
    public function store(CreateDocumentoRequest $request, Documento $documento): RedirectResponse
    {
        Gate::authorize('create',$documento);

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

            $path = $uploadedFile->storeAs('documenti', $uploadedFile->hashName(), 'local');

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

        try {

            // Exclude 'file' before dispatching event
            $validatedForEvent = Arr::except($validated, ['file']);
            NotifyUserOfCreatedDocumento::dispatch($validatedForEvent, $documento);

        } catch (\Exception $emailException) {

            Log::error('Error sending email for documento ID ' . $documento->id . ': ' . $emailException->getMessage());
        
            return to_route('admin.documenti.index')->with(
                $this->flashWarning(__('documenti.error_notify_new_document'))
            );

        }

        return to_route('admin.documenti.index')->with(
            $this->flashSuccess(__('documenti.success_create_document'))
        );

    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Documento $documento): Response
    {
        $documento->loadMissing(['categoria', 'condomini', 'anagrafiche']);

        return Inertia::render('documenti/DocumentiEdit', [
            'documento'   => new DocumentoResource($documento),
            'categories'  => CategoriaDocumentoResource::collection(CategoriaDocumento::all()),
            'condomini'   => CondominioOptionsResource::collection(Condominio::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]); 
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDocumentoRequest $request, Documento $documento): RedirectResponse
    {

        $validated = $request->validated();

        try {

            DB::beginTransaction();

            /** @var \Illuminate\Http\Request $request */
            if ($request->hasFile('file') && $request->file('file')->isValid()) {
                // Delete old file if exists
                if (Storage::exists($documento->path)) {
                    Storage::delete($documento->path);
                }

                $uploadedFile = $request->file('file');
                $path = $uploadedFile->storeAs('documenti', $uploadedFile->hashName(), 'local');

                // Update file related fields
                $documento->path = $path;
                $documento->mime_type = $uploadedFile->getClientMimeType();
                $documento->file_size = $uploadedFile->getSize();
            }

            $documento->update([
                'name'         => $validated['name'] ?? $documento->name,
                'description'  => $validated['description'] ?? $documento->description,
                'path'         => $documento->path,
                'mime_type'    => $documento->mime_type,
                'file_size'    => $documento->file_size,
                'created_by'   => $validated['created_by'] ?? $documento->created_by,
                'category_id'  => $validated['category_id'] ?? $documento->category_id,
                'is_published' => $validated['is_published'] ?? $documento->is_published,
                'is_approved'  => $validated['is_approved'] ?? $documento->is_approved,
            ]);

            if (isset($validated['condomini_ids'])) {
                $documento->condomini()->sync($validated['condomini_ids']);
            }

            $documento->anagrafiche()->sync($validated['anagrafiche'] ?? []);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error updating documento archivio: ' . $e->getMessage());

            return to_route('admin.documenti.index')->with(
                $this->flashError(__('documenti.error_update_document'))
            );
        }

        return to_route('admin.documenti.index')->with(
            $this->flashSuccess(__('documenti.success_update_document'))
        );
    }

    /**
     * Delete a document and its associated file from storage.
     *
     * This method performs the following:
     * - Deletes the physical file from the storage if it exists.
     * - Deletes the document record from the database.
     * - Relies on ON DELETE CASCADE to clean up pivot table relations.
     * - Logs any errors that occur during the process.
     *
     * @param  \App\Models\Documento  $documento  The document instance to be deleted.
     * @return \Illuminate\Http\RedirectResponse  Redirects back with a success or error message.
     */
    public function destroy(Documento $documento): RedirectResponse
    {
        Gate::authorize('delete',$documento);

        try {
            // Start a transaction in case you need to roll back
            DB::beginTransaction();

            // Delete the file from storage
            if (Storage::exists($documento->path)) {
                Storage::delete($documento->path);
            }

            // Delete the database record
            $documento->delete();

            DB::commit();

            return redirect()->back()->with(
                $this->flashSuccess(__('documenti.success_delete_document'))
            );

        } catch (\Exception $e) {
            
            DB::rollBack();

            Log::error('Error deleting documento archivio', [
                'document_id' => $documento->id,
                'message'     => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return redirect()->back()->with(
                $this->flashError(__('documenti.error_delete_document'))
            );
        }   
    }

    /**
     * Download the specified document file.
     *
     * @param  \App\Models\Documento  $documento
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\RedirectResponse
     */
    public function download(Documento $documento)
    {

        try {
            
            if (!Storage::exists($documento->path)) {
                return redirect()->back()->with(
                    $this->flashError(__('documenti.file_not_found'))
                );
            }

            return Storage::download($documento->path, $documento->name);

        } catch (\Exception $e) {

            Log::error('Error downloading documento archivio', [
                'document_id' => $documento->id,
                'message'     => $e->getMessage(),
            ]);

            return redirect()->back()->with(
                $this->flashError(__('documenti.error_downloading_document'))
            );

        }
    }
}
