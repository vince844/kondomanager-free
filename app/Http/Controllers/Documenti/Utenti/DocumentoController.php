<?php

namespace App\Http\Controllers\Documenti\Utenti;

use App\Events\Documenti\NotifyAdminOfCreatedDocumento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documento\Utenti\CreateDocumentoRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Documento;
use App\Services\DocumentoService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasAnagrafica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class DocumentoController extends Controller
{
    use HandleFlashMessages, HasAnagrafica;

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
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Documento::class);

        $validated = $request->validate([
            'categoria' => ['required', 'integer', 'exists:categorie_documento,id']
        ]);

        $anagrafica = $this->getUserAnagrafica();
        $condomini = $anagrafica->condomini;

        return Inertia::render('documenti/user/DocumentiNew', [
            'condomini' => CondominioResource::collection($condomini),
            'categoria' => (int) $validated['categoria'],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateDocumentoRequest $request, Documento $documento): RedirectResponse
    {
        Gate::authorize('create',$documento);

        $validated = $request->validated();

        /** @var \Illuminate\Http\Request $request */
        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return to_route('user.categorie-documenti.index')->with(
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

            if ($validated['is_private']) {
                
                $anagrafica = $this->getUserAnagrafica();
                $documento->anagrafiche()->attach($anagrafica);
            }

            DB::commit();

            try {

                $validatedForEvent = Arr::except($validated, ['file']);

                NotifyAdminOfCreatedDocumento::dispatch($validatedForEvent, $documento);

            } catch (\Exception $emailException) {

                // If an error occurs during email sending, log it and set a message for the email failure
                Log::error('Error user sending email for documento ID: ' . $documento->id . ' - ' . $emailException->getMessage());

                return to_route('user.categorie-documenti.index')->with(
                    $this->flashWarning(__('documenti.error_notify_new_document'))
                );

            }

        } catch (\Exception $e) {
            
            DB::rollback();
            
            Log::error('Error creating documento archivio: ' . $e->getMessage());
        
            return to_route('user.categorie-documenti.index')->with(
                $this->flashError(__('documenti.error_create_document'))
            );

        }

        return to_route('user.categorie-documenti.index')->with(
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
    public function destroy(Documento $documento): RedirectResponse
    {
        Gate::authorize('delete', $documento);

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

            return back()->with(
                $this->flashSuccess(__('documenti.success_delete_document'))
            );

        } catch (\Exception $e) {
            
            DB::rollBack();

            Log::error('Error deleting documento archivio', [
                'document_id' => $documento->id,
                'message'     => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return back()->with(
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
        Gate::authorize('view',$documento);

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
