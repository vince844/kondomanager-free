<?php

namespace App\Http\Controllers\Fornitori\Documenti;

use App\Traits\OrdinaElenco;
use App\Services\Documenti\ArchivioDocumenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fornitore\Documento\CreateFornitoreDocumentoRequest;
use App\Http\Requests\Fornitore\Documento\FornitoreDocumentoIndexRequest;
use App\Http\Requests\Fornitore\Documento\UpdateFornitoreDocumentoRequest;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Http\Resources\Fornitore\FornitoreResource;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Traits\HandleFlashMessages;
use App\Traits\PaginaElenco;
use App\Traits\ScaricaEApprovaDocumento;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class FornitoreDocumentoController extends Controller
{
    use OrdinaElenco;
    use HandleFlashMessages;
    use PaginaElenco;
    use ScaricaEApprovaDocumento;

    /**
     * Display a listing of the resource.
     */
    public function index(FornitoreDocumentoIndexRequest $request, Fornitore $fornitore): Response
    {
        // I documenti si leggono con la scheda del fornitore, e si caricano, si modificano e si eliminano modificandolo
        // (Coda 402).
        Gate::authorize('view', $fornitore);

        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        $documenti = $fornitore->documenti()
            ->with(['condomini', 'createdBy.anagrafica'])
            ->when($validated['name'] ?? false, function ($query, $name) {
                $query->where('name', 'like', "%{$name}%");
            })
            ->tap(fn ($q) => $this->ordina($q, $validated, FornitoreDocumentoIndexRequest::colonneOrdinabili(), predefinita: 'created_at', versoPredefinito: 'desc'))
            ->paginate($validated['per_page'])
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
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Fornitore $fornitore): Response
    {
        Gate::authorize('update', $fornitore);

         return Inertia::render('fornitori/documenti/DocumentiNew', [
            'fornitore'  => $fornitore,
            // Il limite lo decide il server, non noi.
            'limiteFile' => \App\Support\LimiteCaricamento::etichetta(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateFornitoreDocumentoRequest $request, Fornitore $fornitore): RedirectResponse
    {
        Gate::authorize('update', $fornitore);

        $validated = $request->validated();

        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {

            return to_route('admin.fornitori.documenti.index', [
                'fornitore' => $fornitore->id,
            ])->with($this->flashError(__('documenti.no_file_uploaded')));

        } 

        try {

            $uploadedFile = $request->file('file');

            $path = app(ArchivioDocumenti::class)->salva($uploadedFile);

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
        Gate::authorize('update', $fornitore);

        abort_unless($this->appartiene($documento, $fornitore), 404);

        return Inertia::render('fornitori/documenti/DocumentiEdit', [
            'documento'   => new DocumentoResource($documento),
            'fornitore'  => $fornitore,
            // Il limite lo decide il server, non noi.
            'limiteFile' => \App\Support\LimiteCaricamento::etichetta(),
        ]); 
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFornitoreDocumentoRequest $request, Fornitore $fornitore, Documento $documento): RedirectResponse
    {
        Gate::authorize('update', $fornitore);

        abort_unless($this->appartiene($documento, $fornitore), 404);

        $validated = $request->validated();

        try {

            if ($request->hasFile('file') && $request->file('file')->isValid()) {
                // Delete old file if exists
                app(ArchivioDocumenti::class)->elimina($documento->path);

                $uploadedFile = $request->file('file');
                $path = app(ArchivioDocumenti::class)->salva($uploadedFile);

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
        Gate::authorize('update', $fornitore);

        abort_unless($this->appartiene($documento, $fornitore), 404);

        try {

            app(ArchivioDocumenti::class)->elimina($documento->path);

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

    /**
     * Scarica un documento del fornitore: con la scheda del fornitore («Visualizza anagrafiche», `FornitorePolicy::view`),
     * come l'elenco dei suoi documenti. Un documento che non è del fornitore dell'indirizzo, da qui, non c'è (404).
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
     */
    public function download(Fornitore $fornitore, Documento $documento)
    {
        Gate::authorize('view', $fornitore);

        abort_unless($this->appartiene($documento, $fornitore), 404);

        return $this->scaricaIlDocumento($documento);
    }

    /**
     * Approva un documento del fornitore, o ne toglie l'approvazione: si cambia il documento, quindi con «Modifica
     * anagrafiche» (`FornitorePolicy::update`, come caricarlo e rinominarlo), e con «Approva documenti archivio», il
     * permesso dell'interruttore. Un documento che non è del fornitore dell'indirizzo, da qui, non c'è (404).
     */
    public function approvazione(Fornitore $fornitore, Documento $documento): RedirectResponse
    {
        Gate::authorize('update', $fornitore);

        abort_unless($this->appartiene($documento, $fornitore), 404);

        Gate::authorize('approve', $documento);

        return $this->alternaLApprovazione($documento);
    }

    /**
     * Il documento è di questo fornitore?
     *
     * Le rotte risolvono i due parametri, il fornitore e il documento, ognuno per conto suo: la scheda lavora solo sui
     * documenti del fornitore dell'indirizzo (Coda 407). Si guarda la coppia intera, tipo e id, perché il solo id
     * combacerebbe anche con il documento di una persona che ha lo stesso numero.
     */
    private function appartiene(Documento $documento, Fornitore $fornitore): bool
    {
        return $documento->documentable_type === Fornitore::class
            && (int) $documento->documentable_id === (int) $fornitore->id;
    }
}
