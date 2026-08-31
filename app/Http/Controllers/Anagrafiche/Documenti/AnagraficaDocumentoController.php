<?php

namespace App\Http\Controllers\Anagrafiche\Documenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Anagrafica\Documento\AnagraficaDocumentoIndexRequest;
use App\Http\Requests\Anagrafica\Documento\CreateAnagraficaDocumentoRequest;
use App\Http\Requests\Anagrafica\Documento\UpdateAnagraficaDocumentoRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Models\Anagrafica;
use App\Models\Documento;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use App\Traits\OrdinaElenco;
use App\Traits\PaginaElenco;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La scheda «Documenti» di un'anagrafica.
 *
 * ## Perché esiste
 *
 * Fino alla 1.11.0-beta.9 **un'anagrafica non aveva una scheda**: `AnagraficaController::show()`
 * aveva il corpo vuoto — letteralmente `//` — e rispondeva **200 con niente dentro**, cioè una
 * pagina bianca senza nemmeno un errore. Non era una rotta orfana: l'elenco della rubrica ci
 * puntava già col nome della persona, quindi il difetto era **vivo e cliccabile**.
 *
 * ⚠️ È la stessa famiglia del «Call to undefined method» arrivato dal forum nella beta.62 sulle
 * categorie dei documenti, ma peggiore: là c'era un errore, qui c'era il silenzio. Un errore lo si
 * segnala, una pagina bianca fa pensare che sia colpa della propria connessione.
 *
 * ## ⚠️ Quali documenti, ed è la decisione che conta
 *
 * Questa scheda mostra i documenti **della persona** — `Anagrafica::documentiPropri()`, morphMany su
 * `documentable` — cioè quelli che l'amministratore archivia sulla sua scheda: la copia del
 * documento d'identità, una delega per l'assemblea, un contratto d'affitto. È **la stessa forma che
 * il fornitore ha da sempre**, ed è la ragione per cui si carica da qui.
 *
 * Non mostra i documenti dell'archivio di cui la persona è *destinataria*
 * (`Anagrafica::documenti()`, la belongsToMany su `anagrafica_documento`): quelli sono documenti che
 * vivono nell'archivio e riguardano tutti — il verbale mandato all'intero condominio — e la loro
 * casa è l'archivio, dove si caricano e si cancellano. Metterli qui insieme agli altri farebbe
 * rispondere a questa scheda **due domande diverse nella stessa tabella**, e la prima volta che uno
 * cancellasse la riga sbagliata scoprirebbe che erano due cose diverse.
 *
 * Se un giorno servirà anche quella vista, è una **scheda in più**: il layout è costruito per
 * aggiungerne.
 */
class AnagraficaDocumentoController extends Controller
{
    use OrdinaElenco;
    use PaginaElenco;
    use HandleFlashMessages;

    public function index(AnagraficaDocumentoIndexRequest $request, Anagrafica $anagrafica): Response
    {
        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        $documenti = $anagrafica->documentiPropri()
            ->with(['createdBy.anagrafica'])
            ->when($validated['name'] ?? false, function ($query, $name) {
                $query->where('name', 'like', "%{$name}%");
            })
            ->tap(fn ($q) => $this->ordina(
                $q,
                $validated,
                AnagraficaDocumentoIndexRequest::colonneOrdinabili(),
                predefinita: 'created_at',
                versoPredefinito: 'desc'
            ))
            ->paginate($validated['per_page'])
            ->appends($request->all());

        return Inertia::render('anagrafiche/documenti/DocumentiList', [
            // ⚠️ La prop si chiama `anagrafica` **e serve al layout delle schede**, che la legge da
            // `usePage()` per costruire i collegamenti fra «Dettagli» e «Documenti». Cambiandole
            // nome qui, la barra delle schede sparisce senza un errore.
            'anagrafica' => new AnagraficaResource($anagrafica),
            'documenti'  => DocumentoResource::collection($documenti)->resolve(),
            'meta' => [
                'current_page' => $documenti->currentPage(),
                'last_page'    => $documenti->lastPage(),
                'per_page'     => $documenti->perPage(),
                'total'        => $documenti->total(),
            ],
            'filters'   => $request->only(['name']),
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,

            // Il limite lo decide il server, non noi: `LimiteCaricamento` legge le direttive di PHP,
            // così la schermata non promette più di quanto la macchina accetti.
            'limiteFile' => LimiteCaricamento::etichetta(),
        ]);
    }

    /**
     * Il modulo di caricamento, **pagina intera come quella del fornitore**.
     *
     * ⚠️ La prima stesura teneva il modulo in un pannello laterale dentro l'elenco: funzionava, ma
     * il programma la stessa cosa la fa in un altro modo, e due schermate che fanno la stessa cosa
     * in due forme diverse costringono chi le usa a impararle due volte.
     */
    public function create(Anagrafica $anagrafica): Response
    {
        return Inertia::render('anagrafiche/documenti/DocumentiNew', [
            'anagrafica' => new AnagraficaResource($anagrafica),
            // Il limite lo decide il server, non noi.
            'limiteFile' => LimiteCaricamento::etichetta(),
        ]);
    }

    public function edit(Anagrafica $anagrafica, Documento $documento): Response
    {
        // ⚠️ Vale anche qui la guardia dell'`update`: senza, cambiando l'id nell'indirizzo si
        // aprirebbe il modulo di modifica del documento di un'altra persona, con i suoi dati dentro.
        abort_unless($this->appartiene($documento, $anagrafica), 404);

        return Inertia::render('anagrafiche/documenti/DocumentiEdit', [
            'anagrafica' => new AnagraficaResource($anagrafica),
            'documento'  => new DocumentoResource($documento),
            'limiteFile' => LimiteCaricamento::etichetta(),
        ]);
    }

    public function store(CreateAnagraficaDocumentoRequest $request, Anagrafica $anagrafica): RedirectResponse
    {
        $validated = $request->validated();

        // ⚠️ La guardia c'è anche se la regola dichiara `required|file`: un caricamento troncato da
        // `post_max_size` arriva con la richiesta **vuota**, e in quel caso la validazione non vede
        // nemmeno il campo. Senza questo controllo si finirebbe più giù con un `null` in mano.
        if (! $request->hasFile('file') || ! $request->file('file')->isValid()) {
            return to_route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id])
                ->with($this->flashError(__('documenti.no_file_uploaded')));
        }

        try {
            $file = $request->file('file');

            $percorso = $file->storeAs('documenti', $file->hashName(), 'local');

            $anagrafica->documentiPropri()->create([
                'name'         => $validated['name'],
                'description'  => $validated['description'] ?? null,
                'path'         => $percorso,
                'mime_type'    => $file->getClientMimeType(),
                'file_size'    => $file->getSize(),
                'created_by'   => $request->autore(),
                'is_published' => $validated['is_published'],
                'is_approved'  => $validated['is_approved'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Errore caricando un documento di un\'anagrafica: '.$e->getMessage());

            return to_route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id])
                ->with($this->flashError(__('documenti.error_create_document')));
        }

        return to_route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id])
            ->with($this->flashSuccess(__('documenti.success_create_document')));
    }

    public function update(
        UpdateAnagraficaDocumentoRequest $request,
        Anagrafica $anagrafica,
        Documento $documento
    ): RedirectResponse {
        // ⚠️ **Il documento deve essere di questa persona.** Il binding di rotta risolve i due
        // parametri in modo indipendente: senza questa riga, cambiando l'id nell'indirizzo si
        // modificherebbe il documento di un'altra anagrafica passando da questa schermata.
        abort_unless($this->appartiene($documento, $anagrafica), 404);

        $validated = $request->validated();

        try {
            if ($request->hasFile('file') && $request->file('file')->isValid()) {
                // Il vecchio file si toglie solo dopo che il nuovo è stato scritto: al contrario,
                // un errore a metà lascerebbe una riga che punta a un file che non c'è più.
                $file = $request->file('file');
                $nuovoPercorso = $file->storeAs('documenti', $file->hashName(), 'local');

                $vecchioPercorso = $documento->path;

                $documento->path = $nuovoPercorso;
                $documento->mime_type = $file->getClientMimeType();
                $documento->file_size = $file->getSize();

                if ($vecchioPercorso && Storage::disk('local')->exists($vecchioPercorso)) {
                    Storage::disk('local')->delete($vecchioPercorso);
                }
            }

            $documento->update([
                'name'         => $validated['name'],
                'description'  => $validated['description'] ?? null,
                'path'         => $documento->path,
                'mime_type'    => $documento->mime_type,
                'file_size'    => $documento->file_size,
                'is_published' => $validated['is_published'],
                'is_approved'  => $validated['is_approved'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Errore aggiornando un documento di un\'anagrafica: '.$e->getMessage());

            return to_route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id])
                ->with($this->flashError(__('documenti.error_update_document')));
        }

        return to_route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id])
            ->with($this->flashSuccess(__('documenti.success_update_document')));
    }

    public function destroy(Anagrafica $anagrafica, Documento $documento): RedirectResponse
    {
        abort_unless($this->appartiene($documento, $anagrafica), 404);

        try {
            if ($documento->path && Storage::disk('local')->exists($documento->path)) {
                Storage::disk('local')->delete($documento->path);
            }

            $documento->delete();
        } catch (\Throwable $e) {
            Log::error('Errore eliminando un documento di un\'anagrafica: '.$e->getMessage());

            return to_route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id])
                ->with($this->flashError(__('documenti.error_delete_document')));
        }

        return to_route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id])
            ->with($this->flashSuccess(__('documenti.success_delete_document')));
    }

    /**
     * Il documento appartiene davvero a questa anagrafica?
     *
     * Si guarda la coppia morfologica intera — tipo **e** id — perché il solo id combacerebbe anche
     * con il documento di un fornitore che per caso ha lo stesso numero.
     */
    private function appartiene(Documento $documento, Anagrafica $anagrafica): bool
    {
        return $documento->documentable_type === Anagrafica::class
            && (int) $documento->documentable_id === (int) $anagrafica->id;
    }
}
