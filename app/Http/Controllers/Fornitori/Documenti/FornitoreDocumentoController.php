<?php

namespace App\Http\Controllers\Fornitori\Documenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fornitore\Documento\CreateFornitoreDocumentoRequest;
use App\Http\Requests\Fornitore\Documento\FornitoreDocumentoIndexRequest;
use App\Http\Requests\Fornitore\Documento\UpdateFornitoreDocumentoRequest;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Http\Resources\Fornitore\FornitoreResource;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FornitoreDocumentoController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of the resource.
     */
    public function index(FornitoreDocumentoIndexRequest $request, Fornitore $fornitore): Response
    {
        $validated = $request->validated();

        $documenti = $fornitore->documenti()
            ->with(['condomini', 'createdBy.anagrafica'])
            ->when($validated['name'] ?? false, function ($query, $name) {
                $query->where('name', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->appends($request->all());

        return Inertia::render('fornitori/documenti/DocumentiList', [
            'fornitore'   => new FornitoreResource($fornitore),
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
     * Show the form for creating a new resource.
     */
    public function create(Fornitore $fornitore): Response
    {
         return Inertia::render('fornitori/documenti/DocumentiNew', [
            'fornitore'  => $fornitore
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateFornitoreDocumentoRequest $request, Fornitore $fornitore): RedirectResponse
    {
        $validated = $request->validated();

        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {

            return to_route('admin.fornitori.documenti.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashError(__('documenti.no_file_uploaded')));

        } 

        try {

            $uploadedFile = $request->file('file');

            $path = $uploadedFile->storeAs('documenti', $uploadedFile->hashName(), 'local');

            $fornitore->documenti()->create([
                'name'         => $validated['name'],
                'description'  => $validated['description'],
                'path'         => $path,
                'mime_type'    => $uploadedFile->getClientMimeType(),
                'file_size'    => $uploadedFile->getSize(),
                'created_by'   => $validated['created_by'],
            ]);

        } catch (\Exception $e) {
            
            Log::error('Error creating documento fornitore: ' . $e->getMessage());

            return to_route('admin.fornitori.documenti.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashError(__('documenti.error_create_document')));

        }

        return to_route('admin.fornitori.documenti.index', [
                'fornitore' => $fornitore->id,
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
    public function edit(Fornitore $fornitore, Documento $documento): Response
    {

        $documento = $fornitore->documenti()->first();

        return Inertia::render('fornitori/documenti/DocumentiEdit', [
            'documento'   => new DocumentoResource($documento),
            'fornitore'  => $fornitore,
        ]); 
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFornitoreDocumentoRequest $request, Fornitore $fornitore, Documento $documento): RedirectResponse
    {
        $validated = $request->validated();

        try {

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
                'name'         => $validated['name'],
                'description'  => $validated['description'],
                'path'         => $documento->path,
                'mime_type'    => $documento->mime_type,
                'file_size'    => $documento->file_size,
                'created_by'   => $validated['created_by'],
                'is_published' => $validated['is_published'],
                'is_approved'  => $validated['is_approved'],
            ]);

        } catch (\Exception $e) {

            Log::error('Error updating documento immobile: ' . $e->getMessage());

            return to_route('admin.fornitori.documenti.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashError(__('documenti.error_update_document')));

        }

        return to_route('admin.fornitori.documenti.index', [
            'fornitore' => $fornitore->id,
        ])->with($this->flashSuccess(__('documenti.success_update_document')));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Fornitore $fornitore, Documento $documento): RedirectResponse
    {
        try {

            if (Storage::exists($documento->path)) {
                Storage::delete($documento->path);
            }

            $documento->delete();

            return to_route('admin.fornitori.documenti.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashSuccess(__('documenti.success_delete_document')));

        } catch (\Exception $e) {

            Log::error('Error deleting documento fornitore', [
                'document_id' => $documento->id,
                'message'     => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return to_route('admin.fornitori.documenti.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashSuccess(__('documenti.error_delete_document')));

        }   
    }
    
}
