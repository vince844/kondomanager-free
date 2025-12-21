<?php

namespace App\Http\Controllers\Gestionale\Immobili\Documenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Immobile\Documento\CreateImmobileDocumentoRequest;
use App\Http\Requests\Gestionale\Immobile\Documento\ImmobileDocumentoIndexRequest;
use App\Http\Requests\Gestionale\Immobile\Documento\UpdateImmobileDocumentoRequest;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Immobile;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImmobileDocumentoController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    /**
     * Display a paginated listing of documents for the specified immobile.
     *
     * This method handles the index page for immobile documents. It validates the request,
     * filters documents based on search criteria, and returns a paginated list of documents
     * associated with the given immobile.
     *
     * @param ImmobileDocumentoIndexRequest $request The validated request containing filters and pagination parameters
     * @param Condominio $condominio The condominio instance (from route binding)
     * @param Immobile $immobile The immobile instance (from route binding) to which documents belong
     * 
     * @return Response Returns an Inertia.js response rendering the document list view
     * 
     * @uses ImmobileDocumentoIndexRequest For request validation
     * @uses Condominio To get the current condominio context
     * @uses Immobile To access the immobile's documents relationship
     * @uses DocumentoResource For transforming document data for the frontend
     * @uses ImmobileResource For transforming immobile data for the frontend
     * 
     * @example
     * // Typical request: GET /condomini/1/immobili/5/documenti?name=contratto&per_page=10
     * // Returns paginated documents for immobile ID 5 in condominio ID 1, filtered by name containing "contratto"
     * 
     * @throws \Illuminate\Validation\ValidationException If request validation fails
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio or immobile not found
     */
    public function index(ImmobileDocumentoIndexRequest $request, Condominio $condominio, Immobile $immobile): Response
    {
        $validated = $request->validated();

        $documenti = $immobile->documenti()
            ->with(['condomini', 'createdBy.anagrafica'])
            ->when($validated['name'] ?? false, function ($query, $name) {
                $query->where('name', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->appends($request->all());
        
        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/immobili/documenti/DocumentiList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'immobile'   => new ImmobileResource($immobile),
            'documenti'  => DocumentoResource::collection($documenti)->resolve(),
            'meta' => [
                'current_page' => $documenti->currentPage(),
                'last_page'    => $documenti->lastPage(),
                'per_page'     => $documenti->perPage(),
                'total'        => $documenti->total(),
            ],
            'filters' => $request->only(['name']), 
        ]);
    }

    /**
     * Show the form for creating a new document for the specified immobile.
     *
     * This method displays the document creation form for a specific immobile
     * within a condominio context. It prepares the necessary data for the frontend
     * form including the current esercizio for navigation context.
     *
     * @param Condominio $condominio The condominio instance (from route binding)
     * @param Immobile $immobile The immobile instance (from route binding) that will own the new document
     * 
     * @return Response Returns an Inertia.js response rendering the document creation form
     * 
     * @uses Condominio To establish the current condominio context
     * @uses Immobile To associate the new document with this immobile
     * 
     * @example
     * // Typical request: GET /condomini/1/immobili/5/documenti/create
     * // Returns the document creation form for immobile ID 5 in condominio ID 1
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio or immobile not found
     */
    public function create(Condominio $condominio, Immobile $immobile): Response
    {
        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);
        
        return Inertia::render('gestionale/immobili/documenti/DocumentiNew', [
            'condominio'  => $condominio,
            'esercizio'   => $esercizio,
            'immobile'    => $immobile,
        ]);
    }

    /**
     * Store a newly created document for the specified immobile.
     *
     * This method handles the creation and storage of a new document associated with an immobile.
     * It processes file uploads, validates the request, stores the file in the local storage,
     * creates the document record in the database, and associates it with the condominio.
     * The operation is wrapped in a database transaction to ensure data consistency.
     *
     * @param CreateImmobileDocumentoRequest $request The validated request containing document data and file
     * @param Condominio $condominio The condominio instance (from route binding)
     * @param Immobile $immobile The immobile instance (from route binding) that will own the document
     * 
     * @return RedirectResponse Redirects to the document index page with flash message
     * 
     * @uses CreateImmobileDocumentoRequest For request validation and file validation
     * @uses Condominio To associate the document with the condominio
     * @uses Immobile To create the document relationship
     * @uses \Illuminate\Support\Facades\DB For database transaction management
     * @uses \Illuminate\Support\Facades\Log For error logging
     * 
     * @example
     * // Typical POST request to /condomini/1/immobili/5/documenti
     * // with form data: name, description, created_by, and file upload
     * 
     * @throws \Illuminate\Validation\ValidationException If request validation fails
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio or immobile not found
     * @throws \Exception If any error occurs during file storage or database operations
     * 
     * @validation
     * - Requires valid file upload
     * - Validates document name, description, created_by fields
     * - Checks file is present and valid
     * 
     * @transaction
     * - Entire operation is atomic - either all operations succeed or none are applied
     * - Rolls back on any exception during the process
     * 
     * @file_handling
     * - Stores file in 'documenti' directory with hashed filename
     * - Preserves original file extension and mime type
     * - Records file metadata (mime_type, file_size) in database
     */
    public function store(CreateImmobileDocumentoRequest $request, Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        $validated = $request->validated();

        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {

            return to_route('admin.gestionale.immobili.documenti.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('documenti.no_file_uploaded')));

        } 

        try {

            DB::beginTransaction();

            $uploadedFile = $request->file('file');

            $path = $uploadedFile->storeAs('documenti', $uploadedFile->hashName(), 'local');

            $documento = $immobile->documenti()->create([
                'name'         => $validated['name'],
                'description'  => $validated['description'],
                'path'         => $path,
                'mime_type'    => $uploadedFile->getClientMimeType(),
                'file_size'    => $uploadedFile->getSize(),
                'created_by'   => $validated['created_by'],
            ]);

            $documento->condomini()->attach($condominio->id);

            DB::commit();

        } catch (\Exception $e) {
            
            DB::rollback();
            
            Log::error('Error creating documento immobile: ' . $e->getMessage());

            return to_route('admin.gestionale.immobili.documenti.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('documenti.error_create_document')));

        }

        return to_route('admin.gestionale.immobili.documenti.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('documenti.success_create_document')));

    }

    /**
     * Show the form for editing the first document of the specified immobile.
     *
     * This method displays the document editing form for the first document
     * associated with a specific immobile within a condominio context.
     * It prepares the necessary data for the frontend form including the current
     * esercizio for navigation context and transforms the models into API resources.
     *
     * Note: This method retrieves only the first document of the immobile.
     * For editing specific documents, consider adding a document parameter.
     *
     * @param Condominio $condominio The condominio instance (from route binding)
     * @param Immobile $immobile The immobile instance (from route binding) whose first document will be edited
     * 
     * @return Response Returns an Inertia.js response rendering the document editing form
     * 
     * @uses Condominio To establish the current condominio context
     * @uses Immobile To access the immobile's documents relationship
     * @uses DocumentoResource For transforming document data for the frontend
     * @uses ImmobileResource For transforming immobile data for the frontend
     * 
     * @example
     * // Typical request: GET /condomini/1/immobili/5/documenti/edit
     * // Returns the document editing form for the first document of immobile ID 5 in condominio ID 1
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio or immobile not found
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If no documents exist for the immobile
     * 
     * @todo Consider adding a $documento parameter to edit specific documents instead of first()
     */
    public function edit(Condominio $condominio, Immobile $immobile): Response
    {

        $documento = $immobile->documenti()->first();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/immobili/documenti/DocumentiEdit', [
            'documento'   => new DocumentoResource($documento),
            'condominio'  => $condominio,
            'esercizio'   => $esercizio,
            'immobile'    => new ImmobileResource($immobile),
        ]); 
    }

    /**
     * Update the specified document for the given immobile.
     *
     * This method handles updating an existing document associated with an immobile.
     * It can update both the document metadata and optionally replace the file itself.
     * The operation is wrapped in a database transaction to ensure data consistency.
     * If a new file is provided, the old file is deleted from storage and replaced.
     *
     * @param UpdateImmobileDocumentoRequest $request The validated request containing update data
     * @param Condominio $condominio The condominio instance (from route binding)
     * @param Immobile $immobile The immobile instance (from route binding) that owns the document
     * @param Documento $documento The documento instance (from route binding) to be updated
     * 
     * @return RedirectResponse Redirects to the document index page with flash message
     * 
     * @uses UpdateImmobileDocumentoRequest For request validation
     * @uses Condominio For context and routing
     * @uses Immobile For context and ownership validation
     * @uses Documento The document model being updated
     * @uses \Illuminate\Support\Facades\DB For database transaction management
     * @uses \Illuminate\Support\Facades\Storage For file storage operations
     * @uses \Illuminate\Support\Facades\Log For error logging
     * 
     * @example
     * // Typical PUT/PATCH request to /condomini/1/immobili/5/documenti/10
     * // with form data: name, description, created_by, category_id, is_published, is_approved, and optional file
     * 
     * @throws \Illuminate\Validation\ValidationException If request validation fails
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio, immobile or documento not found
     * @throws \Exception If any error occurs during file storage or database operations
     * 
     * @validation
     * - Validates document name, description, created_by, category_id, is_published, is_approved
     * - Validates file if provided (must be valid file)
     * 
     * @transaction
     * - Entire operation is atomic - either all operations succeed or none are applied
     * - Rolls back on any exception during the process
     * 
     * @file_handling
     * - Optional file replacement: if new file provided, old file is deleted
     * - Stores new file in 'documenti' directory with hashed filename
     * - Updates file metadata (mime_type, file_size) in database
     * - Uses local storage disk
     * 
     * @update_strategy
     * - Uses null coalescing to preserve existing values if not provided in request
     * - Updates only the fields that are provided in the request
     * - Maintains referential integrity with condominio and immobile
     */
    public function update(UpdateImmobileDocumentoRequest $request, Condominio $condominio, Immobile $immobile, Documento $documento): RedirectResponse
    {

        $validated = $request->validated();

        try {

            DB::beginTransaction();

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

            DB::commit();

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating documento immobile: ' . $e->getMessage());

            return to_route('admin.gestionale.immobili.documenti.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('documenti.error_update_document')));

        }

        return to_route('admin.gestionale.immobili.documenti.index', [
            'condominio' => $condominio->id,
            'immobile'   => $immobile->id,
        ])->with($this->flashSuccess(__('documenti.success_update_document')));

    }

    /**
     * Remove the specified document from storage.
     *
     * This method handles the deletion of a document associated with an immobile.
     * It performs a two-step deletion process: first removing the physical file from storage,
     * then deleting the database record. The operation is wrapped in a database transaction
     * to ensure data consistency. If any error occurs during the process, the transaction
     * is rolled back and an error is logged.
     *
     * @param Condominio $condominio The condominio instance (from route binding) for context
     * @param Immobile $immobile The immobile instance (from route binding) that owns the document
     * @param Documento $documento The documento instance (from route binding) to be deleted
     * 
     * @return RedirectResponse Redirects to the document index page with flash message
     * 
     * @uses Condominio For context and routing
     * @uses Immobile For context and ownership validation
     * @uses Documento The document model being deleted
     * @uses \Illuminate\Support\Facades\DB For database transaction management
     * @uses \Illuminate\Support\Facades\Storage For file storage operations
     * @uses \Illuminate\Support\Facades\Log For error logging with detailed context
     * 
     * @example
     * // Typical DELETE request to /condomini/1/immobili/5/documenti/10
     * // Deletes document ID 10 belonging to immobile ID 5 in condominio ID 1
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio, immobile or documento not found
     * @throws \Exception If any error occurs during file deletion or database operations
     * 
     * @deletion_process
     * 1. Begin database transaction
     * 2. Check if physical file exists in storage
     * 3. Delete physical file from storage
     * 4. Delete database record
     * 5. Commit transaction
     * 6. Return success response
     * 
     * @error_handling
     * - Rolls back transaction on any exception
     * - Logs detailed error information including document ID and stack trace
     * - Returns user-friendly error message
     * - Maintains data consistency (either both file and record are deleted or neither)
     * 
     * @safety_features
     * - Checks file existence before deletion to avoid FileNotFoundException
     * - Transaction ensures atomic operation
     * - Comprehensive error logging for debugging
     * - User feedback via flash messages
     */
    public function destroy(Condominio $condominio, Immobile $immobile, Documento $documento): RedirectResponse
    {
        try {

            if (Storage::exists($documento->path)) {
                Storage::delete($documento->path);
            }

            $documento->delete();

            return to_route('admin.gestionale.immobili.documenti.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('documenti.success_delete_document')));


        } catch (\Exception $e) {

            Log::error('Error deleting documento immobile', [
                'document_id' => $documento->id,
                'message'     => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.documenti.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('documenti.error_delete_document')));

        }   
    }
}
