<?php

namespace App\Services;

use App\Traits\OrdinaElenco;
use App\Http\Requests\Documento\DocumentoIndexRequest;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Documento;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DocumentoService
{
    // L'ordinamento si applica **qui** e non nel controller: la query la costruisce
    // questo servizio, e applicarlo altrove significherebbe due punti da tenere allineati.
    use OrdinaElenco;

    /**
     * Get documents paginated or limited, scoped by admin or user.
     */
    public function getDocumenti(
        ?Anagrafica $anagrafica = null,
        ?Collection $condominioIds = null,
        array $validated = [],
        ?int $limit = null
    ): Collection|LengthAwarePaginator {
        $query = $this->getScopedBaseQuery($anagrafica, $condominioIds, $validated);

        if ($limit !== null) {
            return $query->limit($limit)->get();
        }

        return $query->tap(fn ($q) => $this->ordina($q, $validated, DocumentoIndexRequest::colonneOrdinabili(), predefinita: 'name', versoPredefinito: 'asc'))
            // ⚠️ Il ripiego non è più la catena: `per_page` arriva **già risolto** dal controller
            // (`App\Traits\PaginaElenco`), che tiene conto della scelta salvata dall'utente e delle
            // impostazioni generali. Resta qui come rete per un chiamante futuro che se ne dimenticasse,
            // perché un elenco che ripiega su dieci righe è meglio di un elenco che va in errore.
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
                     ->withQueryString();
    }

    /**
     * Unified base query builder depending on user role.
     */
    protected function getScopedBaseQuery(
        ?Anagrafica $anagrafica,
        ?Collection $condominioIds,
        array $validated = []
    ): Builder {
        $query = $this->isAdmin()
            ? $this->getAdminBaseQuery($validated)
            : $this->getUserBaseQuery($anagrafica, $condominioIds);

        return $this->applyFilters($query, $validated);
    }

    /**
     * Admin base query with eager loads and ordering.
     */
    protected function getAdminBaseQuery(array $validated): Builder
    {
        return Documento::with(['createdBy', 'condomini', 'anagrafiche', 'categorie'])
                        ->whereNull('documentable_type')
                        ->orderBy('created_at', 'desc');
    }

    /**
     * User base query scoped to anagrafica and condominio.
     */
    protected function getUserBaseQuery(?Anagrafica $anagrafica, ?Collection $condominioIds): Builder
    {
        if (!$anagrafica || $condominioIds->isEmpty()) {
            Log::warning('No anagrafica or condominio IDs provided for user query.');
            return Documento::query()->whereRaw('0 = 1'); // empty result set
        }

        return Documento::with(['anagrafiche', 'condomini', 'createdBy.anagrafica', 'categorie'])
            ->where('is_published', true)
            ->where('is_approved', true)
            ->whereNull('documentable_type')
            ->where(function ($query) use ($anagrafica, $condominioIds) {
                $query->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $anagrafica->id))
                      ->orWhere(function ($q) use ($condominioIds) {
                          $q->whereDoesntHave('anagrafiche')
                            ->whereHas('condomini', fn($sub) => $sub->whereIn('condominio_id', $condominioIds));
                      });
            })
            ->orderBy('created_at', 'desc');
    }

    /**
     * Apply filtering based on validated inputs.
     */
    protected function applyFilters(Builder $query, array $validated): Builder
    {
        return $query
            ->when($validated['search'] ?? false, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($validated['name'] ?? false, fn($q, $n) => $q->where('name', 'like', "%{$n}%"))
            // ⚠️ **`whereHas` e non `whereIn` su una colonna** (1.11.0-beta.10): il filtro cerca
            // i documenti che stanno in **almeno una** delle categorie chieste. Il parametro si
            // chiama ancora `category_id` di proposito — è il nome che viaggia nell'indirizzo, che
            // la barra dei filtri reidrata e che il nome di una categoria usa per linkare qui:
            // rinominarlo romperebbe i collegamenti salvati senza dare niente in cambio.
            ->when(
                // ⚠️ `?? false` non basta qui: `[false]` — cioè «mostrami i privati» — è un valore
                // legittimo che `when()` scarterebbe come vuoto. Si guarda l'**esistenza** della
                // chiave e che l'elenco non sia vuoto.
                ! empty($validated['is_published']),
                fn ($q) => $q->whereIn('is_published', array_map('boolval', $validated['is_published']))
            )
            ->when($validated['category_id'] ?? false, fn ($q, $c) => $q->whereHas(
                'categorie',
                fn ($sub) => $sub->whereIn('categorie_documento.id', $c)
            ))
            // AGGIUNTO: Filtro Many-to-Many per Condomini
            ->when($validated['condominio_id'] ?? false, function ($q, $condominioIds) {
                $q->whereHas('condomini', function ($subQ) use ($condominioIds) {
                    $subQ->whereIn('condomini.id', (array) $condominioIds);
                });
            });
    }

    /**
     * Quanti documenti per ogni categoria, per le schede dell'archivio del condòmino.
     *
     * ⚠️ **Dalla 1.11.0-beta.10 la somma di questi conteggi può superare il numero dei documenti**,
     * ed è corretto: un documento che sta in «Bilanci» e in «Verbali» viene contato in tutte e due,
     * perché in tutte e due lo si trova. Ogni conteggio è vero per la **sua** categoria; è la somma
     * a non voler dire niente, e infatti nessuno la mostra.
     *
     * Il raggruppamento è passato dalla colonna al **legame**: si conta sulla tabella ponte, non su
     * `documenti.category_id` che non esiste più.
     */
    public function getUserDocumentCountsByCategoria(Anagrafica $anagrafica, Collection $condominioIds): Collection
    {
        $query = $this->getUserBaseQuery($anagrafica, $condominioIds);

        $query->getQuery()->orders = null; // Remove orderBy to avoid SQL error

        return $query->join('documento_categoria as dc', 'dc.documento_id', '=', 'documenti.id')
                     ->selectRaw('dc.categoria_documento_id as categoria_id, COUNT(*) as count')
                     ->groupBy('dc.categoria_documento_id')
                     ->pluck('count', 'categoria_id');
    }

    /**
     * Get documents by category, paginated.
     */
    public function getDocumentiByCategoria(
        Anagrafica $anagrafica,
        Collection $condominioIds,
        int $categoriaId,
        array $validated = []
    ): LengthAwarePaginator {
        // ⚠️ `whereHas` sul legame, non più `where('category_id', …)`: un documento compare
        // nell'elenco di **ogni** categoria a cui appartiene, che è il motivo per cui le categorie
        // multiple sono state chieste — il verbale che approva il bilancio si trova sotto
        // «Verbali» e sotto «Bilanci».
        $query = $this->getScopedBaseQuery($anagrafica, $condominioIds, $validated)
                      ->whereHas('categorie', fn ($q) => $q->where('categorie_documento.id', $categoriaId));

        return $query->orderBy('created_at', 'desc')
                     ->tap(fn ($q) => $this->ordina($q, $validated, DocumentoIndexRequest::colonneOrdinabili(), predefinita: 'name', versoPredefinito: 'asc'))
            // ⚠️ Il ripiego non è più la catena: `per_page` arriva **già risolto** dal controller
            // (`App\Traits\PaginaElenco`), che tiene conto della scelta salvata dall'utente e delle
            // impostazioni generali. Resta qui come rete per un chiamante futuro che se ne dimenticasse,
            // perché un elenco che ripiega su dieci righe è meglio di un elenco che va in errore.
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
                     ->withQueryString();
    }

    /**
     * Get aggregated statistics for documents.
     */
    public function getDocumentiStats(): object
    {
        return $this->isAdmin()
            ? $this->getAdminDocumentiStats()
            : $this->getUserDocumentiStats(Auth::user());
    }

    /**
     * Admin document statistics.
     */
    protected function getAdminDocumentiStats(): object
    {
         $stats = Documento::whereNull('documentable_type')
            ->selectRaw('COUNT(*) as total_documents')
            ->selectRaw('SUM(file_size) as total_storage_bytes')
            ->selectRaw('AVG(file_size) as average_size_bytes')
            // ⚠️ **Un intervallo di date, non `MONTH()` e `YEAR()`.** Quelle due funzioni esistono
            // su MySQL e non su SQLite, quindi la pagina dell'archivio rispondeva **500** dentro
            // la suite: era intestabile per costruzione, ed è la ragione per cui non aveva
            // nessun test. Il confronto per intervallo dice la stessa cosa ovunque — e su MySQL
            // è anche più veloce, perché una funzione applicata alla colonna impedisce l'uso
            // dell'indice mentre un intervallo no.
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as uploaded_this_month', [
                now()->startOfMonth(),
                now()->startOfMonth()->addMonth(),
            ])
            ->first();

        return (object) [
            'total_storage_bytes' => (int) ($stats->total_storage_bytes ?? 0),
            'total_documents'     => (int) ($stats->total_documents ?? 0),
            'uploaded_this_month' => (int) ($stats->uploaded_this_month ?? 0),
            'average_size_bytes'  => (float) ($stats->average_size_bytes ?? 0),
        ];
    }

    /**
     * User document statistics.
     */
    protected function getUserDocumentiStats($user): object
    {
        $anagrafica = $user->anagrafica;
        $condominioIds = optional($anagrafica)->condomini->pluck('id') ?? collect();

        if (!$anagrafica || $condominioIds->isEmpty()) {
            return (object) [
                'total_storage_bytes' => 0,
                'total_documents'     => 0,
                'uploaded_this_month' => 0,
                'average_size_bytes'  => 0,
            ];
        }

        $stats = $this->getUserBaseQuery($anagrafica, $condominioIds)
            ->selectRaw('COUNT(*) as total_documents')
            ->selectRaw('COALESCE(SUM(file_size), 0) as total_storage_bytes')
            ->selectRaw('COALESCE(AVG(file_size), 0) as average_size_bytes')
            // Stesso intervallo di date del riquadro amministratore qui sopra, e per la stessa
            // ragione: `MONTH()` e `YEAR()` non esistono su SQLite. Il gemello lasciato indietro
            // sarebbe rimasto rotto nella suite finché non ci fosse passato un test — cioè, viste
            // le due volte che è già successo su questo modulo, per un pezzo.
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as uploaded_this_month', [
                now()->startOfMonth(),
                now()->startOfMonth()->addMonth(),
            ])
            ->first();

        return (object) [
            'total_storage_bytes' => (int) $stats->total_storage_bytes,
            'total_documents'     => (int) $stats->total_documents,
            'uploaded_this_month' => (int) $stats->uploaded_this_month,
            'average_size_bytes'  => (float) $stats->average_size_bytes,
        ];
    }

    /**
     * Check if current user is admin or collaborator.
     */
    protected function isAdmin(): bool
    {
        $user = Auth::user();
        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]) ||
               $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value);
    }
}
