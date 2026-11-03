<?php

namespace App\Http\Controllers\Documenti\Utenti;

use App\Events\Documenti\NotifyAdminOfCreatedDocumento;
use App\Services\Documenti\ArchivioDocumenti;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documento\Utenti\CreateDocumentoRequest;
use App\Http\Requests\Documento\Utenti\UpdateDocumentoRequest;
use App\Http\Resources\Portale\CondominioPortaleResource;
use App\Http\Resources\Portale\DocumentoPortaleResource;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
use App\Services\DocumentoService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasAnagrafica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
            // Coda 260: i propri condomìni con id e nome, senza le note dell'amministratore.
            'condomini' => CondominioPortaleResource::collection($condomini),
            'categoria' => (int) $validated['categoria'],
            // ⚠️ **L'elenco completo, dalla 1.11.0-beta.10.** Prima non serviva: la categoria era
            // una sola e arrivava dall'indirizzo, invisibile. Ora il condòmino può aggiungerne —
            // ed è il senso della beta — quindi il modulo deve poterle mostrare. Senza questa
            // riga il campo ci sarebbe e resterebbe vuoto, che è peggio del campo assente.
            'categories' => CategoriaDocumentoResource::collection(
                CategoriaDocumento::orderBy('name')->get()
            ),
            // Il limite lo decide il server, non noi.
            'limiteFile' => \App\Support\LimiteCaricamento::etichetta(),
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

            $path = app(ArchivioDocumenti::class)->salva($uploadedFile);

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

            $documento->categorie()->attach($validated['categorie']);

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
     * Show the form for editing the specified resource.
     */
    public function edit(Documento $documento): Response
    {
        Gate::authorize('update',$documento);

        $this->soloDellArchivio($documento);

        // Fino alla 1.11.0-beta.39 questa pagina mandava anche 'condomini' (l'elenco intero,
        // Condominio::all()) e 'anagrafiche' (Anagrafica::all(), con codice fiscale, email, PEC e
        // telefono di tutti) — nessuno dei due era nella dichiarazione dei props di
        // DocumentiEdit.vue, e UpdateDocumentoRequest non accetta né condomini_ids né anagrafiche
        // in ingresso: erano dati morti, mandati al browser di ogni condòmino con questo permesso
        // senza che nulla li leggesse (Coda 183).
        $documento->loadMissing('categorie');

        return Inertia::render('documenti/user/DocumentiEdit', [
            'documento'   => new DocumentoPortaleResource($documento),
            'categories'  => CategoriaDocumentoResource::collection(CategoriaDocumento::all()),
            // Il limite lo decide il server, non noi.
            'limiteFile' => \App\Support\LimiteCaricamento::etichetta(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDocumentoRequest $request, Documento $documento): RedirectResponse
    {
        Gate::authorize('update',$documento);

        $this->soloDellArchivio($documento);

        $validated = $request->validated();

        try {

            DB::beginTransaction();

            /** @var \Illuminate\Http\Request $request */
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
                'name'         => $validated['name'] ?? $documento->name,
                'description'  => $validated['description'] ?? $documento->description,
                'path'         => $documento->path,
                'mime_type'    => $documento->mime_type,
                'file_size'    => $documento->file_size,
                // Coda 303: autore, pubblicazione e approvazione non cambiano in modifica. Chi
                // modifica il documento di un altro (con un ruolo su misura) non ne diventa l'autore,
                // e la modifica non lo approva né lo ritira: l'approvazione cambia solo
                // dall'interruttore dell'amministratore (decisione 127.2).
                'updated_by'   => Auth::id(),
            ]);

            // ⚠️ **Solo se il modulo le ha mandate, ed è la protezione che conta di più qui.**
            // Il condòmino arriva a questo modulo da **una** categoria, quella che stava sfogliando.
            // Se il modulo ne mandasse una sola, un condòmino che corregge il nome di un documento
            // che sta in tre categorie **ne cancellerebbe due senza saperlo** — nessun errore,
            // nessun messaggio. È la ragione per cui la tendina multipla va anche qui, e non solo
            // nei moduli dell'amministratore.
            if (isset($validated['categorie'])) {
                $documento->categorie()->sync($validated['categorie']);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error user updating documento archivio: ' . $e->getMessage());

            return to_route('user.categorie-documenti.index')->with(
                $this->flashError(__('documenti.error_update_document'))
            );
        }

        return to_route('user.categorie-documenti.index')->with(
            $this->flashSuccess(__('documenti.success_update_document'))
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Documento $documento): RedirectResponse
    {
        Gate::authorize('delete', $documento);

        $this->soloDellArchivio($documento);

        try {
            // Start a transaction in case you need to roll back
            DB::beginTransaction();

            // Delete the file from storage
            app(ArchivioDocumenti::class)->elimina($documento->path);

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
     * Il nome di scaricamento lo decide `Documento::nomeDiScaricamento()`, non questo metodo: la
     * stessa regola serve anche al controller dell'area amministratore, e quando viveva in due
     * copie è stata corretta in una sola — è da lì che è nata la segnalazione dal forum che
     * questo metodo chiude.
     *
     * Il disco è `local` **esplicito**, come nei `store()` di entrambe le aree: senza, la lettura
     * andrebbe sul disco di default, che oggi coincide per configurazione ma non per garanzia.
     *
     * @param  \App\Models\Documento $documento
     */
    public function download(Documento $documento)
    {
        Gate::authorize('view',$documento);

        $this->soloDellArchivio($documento);

        try {

            if (!app(ArchivioDocumenti::class)->esiste($documento->path)) {
                return redirect()->back()->with(
                    $this->flashError(__('documenti.file_not_found'))
                );
            }

            return app(ArchivioDocumenti::class)->scarica($documento->path, $documento->nomeDiScaricamento());

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

    /**
     * Le rotte dei documenti del portale lavorano sui documenti dell'archivio, quelli senza un proprietario, come li
     * mostra il suo elenco (`DocumentoService::getUserBaseQuery`), anche per chi ha il pannello: un documento di una
     * persona, di un fornitore, di un'unità o di una fattura per queste rotte non c'è (404). Si apre e si cambia dalla
     * pagina del proprietario, con il suo permesso.
     */
    private function soloDellArchivio(Documento $documento): void
    {
        abort_unless($documento->documentable_type === null, 404);
    }
}
