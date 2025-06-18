<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Documento;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DocumentoService
{
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Get paginated comunicazioni based on user role.
     *
     * @param Anagrafica|null $anagrafica
     * @param Collection|null $condominioIds
     * @param array $validated
     * @return LengthAwarePaginator
     */

    public function getDocumenti(
        ?Anagrafica $anagrafica = null,
        ?Collection $condominioIds = null,
        array $validated = [],
        ?int $limit = null
    ): Collection|LengthAwarePaginator {

        $query = $this->isAdmin()
            ? $this->getAdminScopedBaseQuery($validated)    // <-- return Builder
            : $this->getUserScopedBaseQuery($anagrafica, $condominioIds); // <-- return Builder

        // Apply filters on base query
        $query = $this->applyFilters($query, $validated);

        if ($limit !== null) {
            return $query->limit($limit)->get();  // returns a Collection
        }

        return $query->paginate($validated['per_page'] ?? self::DEFAULT_PER_PAGE)
                     ->withQueryString();  // returns LengthAwarePaginator
    }

    /**
     * Return admin base query builder without pagination.
     */
    public function getAdminScopedBaseQuery(array $validated = []): Builder
    {
        $query = Documento::with(['createdBy', 'condomini', 'anagrafiche', 'categoria']);
        return $this->applyFilters($query, $validated);
    }

    /**
     * Get user-scoped comunicazioni query with filters and pagination.
     *
     * @param Anagrafica|null $anagrafica
     * @param Collection|null $condominioIds
     * @param array $validated
     * @return LengthAwarePaginator
     */
    private function getUserScopedQuery(
        ?Anagrafica $anagrafica,
        ?Collection $condominioIds,
        array $validated
    ): LengthAwarePaginator {
        $query = $this->buildUserScopedBaseQuery($anagrafica, $condominioIds);
        $query = $this->applyFilters($query, $validated);

        return $query
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? self::DEFAULT_PER_PAGE)
            ->withQueryString();
    }

    /**
     * Expose the user-scoped base query for external use.
     *
     * @param Anagrafica|null $anagrafica
     * @param Collection|null $condominioIds
     * @return Builder
     */
    public function getUserScopedBaseQuery(
        ?Anagrafica $anagrafica,
        ?Collection $condominioIds
    ): Builder {
        return $this->buildUserScopedBaseQuery($anagrafica, $condominioIds);
    }

    /**
     * Build base query for user-scoped comunicazioni.
     *
     * @param Anagrafica|null $anagrafica
     * @param Collection|null $condominioIds
     * @return Builder
     */
    private function buildUserScopedBaseQuery(
        ?Anagrafica $anagrafica,
        ?Collection $condominioIds
    ): Builder {
        if (!$anagrafica || !$condominioIds) {
            Log::warning('No anagrafica or condominio IDs provided for user-scoped query.');
            return Documento::query()->whereRaw('1 = 0');
        }

        return Documento::with(['anagrafiche', 'condomini', 'createdBy.anagrafica', 'categoria'])
            ->where('is_published', true)
            ->where('is_approved', true)
            ->where(function ($query) use ($anagrafica, $condominioIds) {
                $query->where(function ($q) use ($anagrafica) {
                    $q->whereHas('anagrafiche', function ($subQ) use ($anagrafica) {
                        $subQ->where('anagrafica_id', $anagrafica->id);
                    });
                })
                ->orWhere(function ($q) use ($condominioIds) {
                    $q->whereDoesntHave('anagrafiche')
                      ->whereHas('condomini', function ($subQ) use ($condominioIds) {
                          $subQ->whereIn('condominio_id', $condominioIds);
                      });
                });
            });
    }

    /**
     * Get admin-scoped comunicazioni query with filters and pagination.
     *
     * @param array $validated
     * @return LengthAwarePaginator
     */
    private function getAdminScopedQuery(array $validated = []): LengthAwarePaginator
    {
        $query = Documento::with(['createdBy', 'condomini', 'anagrafiche', 'categoria']);
        $query = $this->applyFilters($query, $validated);

        return $query
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? self::DEFAULT_PER_PAGE)
            ->withQueryString();
    }

    /**
     * Apply filters to the query based on validated data.
     *
     * @param Builder $query
     * @param array $validated
     * @return Builder
     */
    private function applyFilters(Builder $query, array $validated): Builder
    {
        return $query
            ->when($validated['search'] ?? false, fn($q, $search) =>
                $q->where('name', 'like', "%{$search}%")
            )
            ->when($validated['name'] ?? false, fn($q, $name) =>
                $q->where('name', 'like', "%{$name}%")
            ) 
            ->when($validated['category_id'] ?? false, fn($q, $categories) =>
                $q->whereIn('category_id', $categories)
            );
    }

    public function getUserDocumentCountsByCategoria(Anagrafica $anagrafica, Collection $condominioIds): Collection
    {
        return $this->getUserScopedBaseQuery($anagrafica, $condominioIds)
            ->selectRaw('category_id, COUNT(*) as count')
            ->groupBy('category_id')
            ->pluck('count', 'category_id');
    }

    public function getDocumentiByCategoria(
        Anagrafica $anagrafica,
        Collection $condominioIds,
        int $categoriaId,
        array $validated = []
    ): LengthAwarePaginator {
        return $this->isAdmin()
            ? $this->getAdminDocumentiByCategoria($categoriaId, $validated)
            : $this->getUserDocumentiByCategoria($anagrafica, $condominioIds, $categoriaId, $validated);
    }

    private function getUserDocumentiByCategoria(
        Anagrafica $anagrafica,
        Collection $condominioIds,
        int $categoriaId,
        array $validated = []
    ): LengthAwarePaginator {
        $query = $this->getUserScopedBaseQuery($anagrafica, $condominioIds)
                    ->where('category_id', $categoriaId);

        $query = $this->applyFilters($query, $validated);

        return $query
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? self::DEFAULT_PER_PAGE)
            ->withQueryString();
    }

    private function getAdminDocumentiByCategoria(
        int $categoriaId,
        array $validated = []
    ): LengthAwarePaginator {
        $query = Documento::query()
            ->where('category_id', $categoriaId)
            ->with(['createdBy', 'condomini', 'anagrafiche', 'categoria']);

        $query = $this->applyFilters($query, $validated);

        return $query
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? self::DEFAULT_PER_PAGE)
            ->withQueryString();
    }

    /**
     * Get statistics on documents based on user role.
     *
     * @return object
     */
    public function getDocumentiStats(): object
    {
        return $this->isAdmin()
            ? $this->getAdminDocumentiStats()
            : $this->getUserDocumentiStats(Auth::user());
    }

    /**
     * Get admin-specific document stats (all documents).
     *
     * @return object
     */
    private function getAdminDocumentiStats(): object
    {
        $query = Documento::query();

        return (object) [
            'total_storage_bytes' => (int) $query->sum('file_size'),
            'total_documents'     => (int) $query->count(),
            'uploaded_this_month' => (int) $query->whereMonth('created_at', now()->month)
                                                 ->whereYear('created_at', now()->year)
                                                 ->count(),
            'average_size_bytes'  => (float) $query->avg('file_size') ?: 0,
        ];
    }

    /**
     * Get user-specific document stats filtered by user's anagrafica and condominio.
     *
     * @param \App\Models\User $user
     * @return object
     */
    private function getUserDocumentiStats($user): object
    {
        $anagrafica = $user->anagrafica;
        $condominioIds = optional($anagrafica)->condomini->pluck('id') ?? collect();

        if (!$anagrafica || $condominioIds->isEmpty()) {
            return (object) [
                'total_storage_bytes' => 0,
                'total_documents' => 0,
                'uploaded_this_month' => 0,
                'average_size_bytes' => 0,
            ];
        }

        $query = $this->getUserScopedBaseQuery($anagrafica, $condominioIds);

        return (object) [
            'total_storage_bytes' => (int) $query->sum('size'),
            'total_documents' => (int) $query->count(),
            'uploaded_this_month' => (int) $query->whereMonth('created_at', now()->month)
                                                ->whereYear('created_at', now()->year)
                                                ->count(),
            'average_size_bytes' => (float) $query->avg('size') ?: 0,
        ];
    }

    /**
     * Check if the current user is an administrator or collaborator.
     *
     * @return bool
     */
    private function isAdmin(): bool
    {
        $user = Auth::user();
        return $user->hasRole(['amministratore', 'collaboratore']) ||
               $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value);
    }
}
