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
use App\Traits\PaginaElenco;
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
    use HandleFlashMessages, PaginaElenco;

    /**
     * Create a new controller instance.
     *
     * @param \App\Services\DocumentoService $documentoService
     * 
     */
    public function __construct(
        private DocumentoService $documentoService,
    ) {}
    
    /**
     * Display a paginated listing of documents with stats and active filters.
     *
     * @param  \App\Http\Requests\Documento\DocumentoIndexRequest $request
     * @param  \App\Models\Documento $documento
     * @return \Inertia\Response
     */
    public function index(DocumentoIndexRequest $request, Documento $documento): Response
    {
        Gate::authorize('view', $documento);

        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, prima di passare il tutto alla Service: la
        // scelta esplicita se c'è, altrimenti quella già fatta dall'utente su questo elenco.
        $validated['per_page'] = $this->righePerPagina($request);

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
            'filters' => Arr::only($validated, ['name', 'category_id', 'condominio_id']),
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Show the form for creating a new document.
     *
     * @param  \App\Models\Documento $documento
     * @return \Inertia\Response
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
     * - Validates incoming form data via CreateDocumentoRequest.
     * - Verifies that a file was uploaded and is valid.
     * - Stores the uploaded file in `storage/app/documenti` using a hashed filename.
     * - Creates a new Documento record and attaches related condomini and anagrafiche via pivot tables.
     * - Dispatches a notification event after a successful save.
     * - Wraps persistence in a DB transaction; rolls back on failure.
     *
     * @param  \App\Http\Requests\Documento\CreateDocumentoRequest $request
     * @param  \App\Models\Documento $documento
     * @return \Illuminate\Http\RedirectResponse
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
     * Show the form for editing the specified document.
     *
     * @param  \App\Models\Documento $documento
     * @return \Inertia\Response
     */
    public function edit(Documento $documento): Response
    {
        Gate::authorize('update',$documento);

        $documento->loadMissing(['categoria', 'condomini', 'anagrafiche']);

        return Inertia::render('documenti/DocumentiEdit', [
            'documento'   => new DocumentoResource($documento),
            'categories'  => CategoriaDocumentoResource::collection(CategoriaDocumento::all()),
            'condomini'   => CondominioOptionsResource::collection(Condominio::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]); 
    }

    /**
     * Update the specified document in storage.
     *
     * If a new file is uploaded, the old file is deleted from disk and replaced.
     * Pivot relations for condomini and anagrafiche are synced on every update.
     * Wraps all persistence in a DB transaction; rolls back on failure.
     *
     * @param  \App\Http\Requests\Documento\UpdateDocumentoRequest $request
     * @param  \App\Models\Documento $documento
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateDocumentoRequest $request, Documento $documento): RedirectResponse
    {
        Gate::authorize('update',$documento);

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
     * Delete a document record and its associated file from storage.
     *
     * - Deletes the physical file from disk if it exists.
     * - Deletes the Documento record; pivot relations are cleaned up via ON DELETE CASCADE.
     * - Wraps all operations in a DB transaction; rolls back on failure.
     *
     * @param  \App\Models\Documento $documento
     * @return \Illuminate\Http\RedirectResponse
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
     * Stream the specified document as a file download.
     *
     * The file is stored on disk with a hashed filename that preserves the original
     * extension (via hashName()), but $documento->name holds a user-defined title
     * with no extension. The extension is extracted from the stored path and appended
     * to the download filename if not already present, so the browser receives a
     * correctly named file regardless of the operating system or browser in use.
     *
     * @param  \App\Models\Documento $documento
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
     */
    public function download(Documento $documento)
    {
        Gate::authorize('view', $documento);

        try {

            if (!Storage::disk('local')->exists($documento->path)) {
                return redirect()->back()->with(
                    $this->flashError(__('documenti.file_not_found'))
                );
            }

            // Il file è salvato con hashName() che preserva l'estensione originale
            // (es. documenti/a1b2c3.pdf), ma $documento->name è il titolo senza estensione.
            // Appendiamo l'estensione al nome solo se non è già presente.
            $extension    = pathinfo($documento->path, PATHINFO_EXTENSION);
            $downloadName = $documento->name;

            if (!empty($extension) && !str_ends_with(strtolower($downloadName), '.' . strtolower($extension))) {
                $downloadName .= '.' . $extension;
            }

            $percorsoAssoluto = Storage::disk('local')->path($documento->path);

            return response()->download($percorsoAssoluto, $downloadName);

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
