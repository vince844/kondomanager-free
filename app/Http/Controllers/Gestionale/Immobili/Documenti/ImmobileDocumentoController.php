<?php

namespace App\Http\Controllers\Gestionale\Immobili\Documenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Immobile\Documento\CreateImmobileDocumentoRequest;
use App\Http\Requests\Gestionale\Immobile\Documento\UpdateImmobileDocumentoRequest;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Immobile;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImmobileDocumentoController extends Controller
{
    use HandleFlashMessages;
    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio, Immobile $immobile): Response
    {

        $documenti = $immobile->documenti()
            ->with(['condomini', 'createdBy.anagrafica'])
            ->paginate(config('pagination.default_per_page'))
            ->withQueryString();

        return Inertia::render('gestionale/immobili/documenti/DocumentiList', [
            'condominio' => $condominio,
            'immobile'   => new ImmobileResource($immobile),
            'documenti'  => DocumentoResource::collection($documenti)->resolve(),
            'meta' => [
                'current_page' => $documenti->currentPage(),
                'last_page'    => $documenti->lastPage(),
                'per_page'     => $documenti->perPage(),
                'total'        => $documenti->total(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio, Immobile $immobile): Response
    {
          return Inertia::render('gestionale/immobili/documenti/DocumentiNew', [
            'condominio'  => $condominio,
            'immobile'    => $immobile,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateImmobileDocumentoRequest $request, Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        $validated = $request->validated();

        /** @var \Illuminate\Http\Request $request */
        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {

            return to_route('admin.gestionale.immobili.documenti.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('documenti.no_file_uploaded')));

        } 

        try {

            DB::beginTransaction();

            /** @var \Illuminate\Http\Request $request */
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
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Condominio $condominio, Immobile $immobile): Response
    {

        $documento = $immobile->documenti()->first();

        return Inertia::render('gestionale/immobili/documenti/DocumentiEdit', [
            'documento'   => new DocumentoResource($documento),
            'condominio'  => $condominio,
            'immobile'    => new ImmobileResource($immobile),
        ]); 
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateImmobileDocumentoRequest $request, Condominio $condominio, Immobile $immobile, Documento $documento): RedirectResponse
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
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Immobile $immobile, Documento $documento): RedirectResponse
    {
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

            return to_route('admin.gestionale.immobili.documenti.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('documenti.success_delete_document')));


        } catch (\Exception $e) {
            
            DB::rollBack();

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
