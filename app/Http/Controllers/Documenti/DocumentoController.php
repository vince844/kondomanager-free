<?php

namespace App\Http\Controllers\Documenti;

use App\Events\Documenti\NotifyUserOfCreatedDocumento;
use App\Events\Notifiche\DestinatariDaAvvisare;
use App\Services\Notifiche\DestinatariNotifica;
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
use Illuminate\Support\Facades\Auth;
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
            /*
             * ⚠️ **Il tipo che torna indietro deve combaciare con quello che l'opzione del filtro
             * contiene — e i due filtri di questa barra non usano lo stesso tipo.**
             *
             * Dalla query string gli identificativi arrivano sempre come stringhe (`['3']`), anche
             * se la regola li valida `integer`: Laravel controlla, non converte. La barra si
             * reidrata confrontando questi valori con le opzioni, e il confronto è un `Set.has()`,
             * che non converte niente.
             *
             * Le due composable che alimentano i due filtri emettono tipi **opposti**:
             * `useCategorieDocumenti` un numero (`categoria.id`), `useCondomini` una stringa
             * (`String(c.id)`). Finché i filtri venivano solo scritti dall'interfaccia la
             * divergenza era invisibile — ogni valore combaciava con sé stesso; si vede solo ora
             * che si reidratano dal server.
             *
             * Un cast unico per tutti gli array accenderebbe una pillola e spegnerebbe l'altra —
             * e sul condominio farebbe di peggio: cliccando la voce nel menu la si
             * **aggiungerebbe** invece di toglierla, lasciando un filtro che dal menu non si può
             * più rimuovere. Trovato dalla revisione avversariale della beta.62.
             *
             * ⛔ **La correzione giusta è togliere la divergenza, e non si fa qui.** `useCondomini`
             * è condivisa con le barre di comunicazioni, segnalazioni e utenti: allinearla ai
             * numeri porta la correzione fuori dal perimetro di questa beta, su tre pagine che le
             * segnalazioni non riguardano. La voce è in `docs/roadmap.md` con la misura; qui si
             * rispetta la convenzione esistente, filtro per filtro.
             */
            'filters' => [
                ...Arr::only($validated, ['name']),
                ...(isset($validated['category_id'])
                    ? ['category_id' => array_map('intval', $validated['category_id'])]
                    : []),
                ...(isset($validated['condominio_id'])
                    ? ['condominio_id' => array_map('strval', $validated['condominio_id'])]
                    : []),
                ...(isset($validated['is_published'])
                    ? ['is_published' => array_map('boolval', $validated['is_published'])]
                    : []),
            ],
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
            'anagrafiche' => [],
            // Il limite lo decide il server, non noi: la schermata scriveva un numero fisso
            // (o non ne scriveva nessuno) mentre la regola ne accettava un altro.
            'limiteFile' => \App\Support\LimiteCaricamento::etichetta(),
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
                'is_published' => $validated['is_published'],
                'is_approved'  => $validated['is_approved'],
            ]);

            // Le categorie sono un legame, non più una colonna: si attaccano dopo la creazione.
            $documento->categorie()->attach($validated['categorie']);

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

        $documento->loadMissing(['categorie', 'condomini', 'anagrafiche']);

        return Inertia::render('documenti/DocumentiEdit', [
            'documento'   => new DocumentoResource($documento),
            'categories'  => CategoriaDocumentoResource::collection(CategoriaDocumento::all()),
            'condomini'   => CondominioOptionsResource::collection(Condominio::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all()),
            // Il limite lo decide il server, non noi: la schermata scriveva un numero fisso
            // (o non ne scriveva nessuno) mentre la regola ne accettava un altro.
            'limiteFile' => \App\Support\LimiteCaricamento::etichetta(),
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

        /*
         * ⚠️ **I destinatari si leggono PRIMA di toccare le pivot** — vedi la nota gemella in
         * `Comunicazioni\ComunicazioneController::update()`, dove sta il perché per esteso.
         */
        $risolutore = app(DestinatariNotifica::class);
        $destinatariPrima = $risolutore->perModello($documento);

        try {

            DB::beginTransaction();

            /** @var \Illuminate\Http\Request $request */
            if ($request->hasFile('file') && $request->file('file')->isValid()) {
                // Delete old file if exists
                if (Storage::disk('local')->exists($documento->path)) {
                    Storage::disk('local')->delete($documento->path);
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
                // ⚠️ Lo mette il server, non il modulo: vedi la nota gemella negli altri due.
                'updated_by'   => Auth::id(),
                'is_published' => $validated['is_published'] ?? $documento->is_published,
                'is_approved'  => $validated['is_approved'] ?? $documento->is_approved,
            ]);

            // ⚠️ `sync` **solo se il modulo le ha mandate**: un `sync([])` su una richiesta che non
            // le porta cancellerebbe tutte le categorie del documento senza che nessuno l'abbia
            // chiesto. La regola di validazione è `sometimes`, quindi l'assenza è legittima.
            if (isset($validated['categorie'])) {
                $documento->categorie()->sync($validated['categorie']);
            }

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

        try {

            $this->avvisaDopoLaModifica($documento, $destinatariPrima, $validated);

        } catch (\Exception $emailException) {

            Log::error('Error notifying update for documento ID ' . $documento->id . ': ' . $emailException->getMessage());

            return to_route('admin.documenti.index')->with(
                $this->flashWarning(__('documenti.error_notify_updated_document'))
            );

        }

        return to_route('admin.documenti.index')->with(
            $this->flashSuccess(__('documenti.success_update_document'))
        );
    }

    /**
     * Chi va avvisato dopo una modifica, e con quale dei due avvisi.
     *
     * Gemella di `Comunicazioni\ComunicazioneController::avvisaDopoLaModifica()`, dove sta la
     * spiegazione per esteso: i nuovi arrivati ricevono l'avviso di *creazione* sempre, chi c'era
     * già riceve quello di *modifica* solo se l'amministratore spunta la casella.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $destinatariPrima
     * @param  array<string, mixed>  $validated
     */
    private function avvisaDopoLaModifica(Documento $documento, $destinatariPrima, array $validated): void
    {
        $destinatariDopo = app(DestinatariNotifica::class)->perModello($documento->fresh());

        $nuovi = $destinatariDopo->diff($destinatariPrima)->values()->all();

        if ($nuovi !== []) {
            DestinatariDaAvvisare::dispatch($documento, $nuovi, 'nuovo');
        }

        if (! ($validated['avvisa_destinatari'] ?? false)) {
            return;
        }

        $giaDestinatari = $destinatariDopo->intersect($destinatariPrima)->values()->all();

        if ($giaDestinatari !== []) {
            DestinatariDaAvvisare::dispatch($documento, $giaDestinatari, 'aggiornato');
        }
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
            if (Storage::disk('local')->exists($documento->path)) {
                Storage::disk('local')->delete($documento->path);
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
     * Il nome di scaricamento lo decide `Documento::nomeDiScaricamento()`, che porta con sé la
     * spiegazione del perché serve. Fino alla beta.62 la regola era scritta qui dentro, e il
     * gemello dell'area utente era rimasto senza: la seconda segnalazione dal forum è arrivata
     * da lì. Una regola in un posto solo non si può correggere a metà.
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

            $percorsoAssoluto = Storage::disk('local')->path($documento->path);

            return response()->download($percorsoAssoluto, $documento->nomeDiScaricamento());

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
