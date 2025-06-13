<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
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
        array $validated = []
    ): LengthAwarePaginator {
        return $this->isAdmin()
            ? $this->getAdminScopedQuery($validated)
            : $this->getUserScopedQuery($anagrafica, $condominioIds, $validated);
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
            return Comunicazione::query()->whereRaw('1 = 0');
        }

        return Comunicazione::with(['anagrafiche', 'condomini', 'createdBy.anagrafica'])
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
     * Get statistics on comunicazioni based on user role.
     *
     * @return object
     */
/*     public function getComunicazioniStats(): object
    {
        return $this->isAdmin()
            ? $this->getStatsQuery(Comunicazione::query())
            : $this->getUserStats(Auth::user());
    } */

    /**
     * Get user-specific comunicazioni statistics.
     *
     * @param \App\Models\User $user
     * @return object
     */
/*     private function getUserStats($user): object
    {
        $anagrafica = $user->anagrafica;
        $condominioIds = optional($anagrafica)->condomini->pluck('id') ?? collect();

        if (!$anagrafica || $condominioIds->isEmpty()) {
            return (object) ['bassa' => 0, 'media' => 0, 'alta' => 0, 'urgente' => 0];
        }

        $query = $this->getUserScopedBaseQuery($anagrafica, $condominioIds);
        return $this->getStatsQuery($query);
    } */

    /**
     * Run aggregation query to count comunicazioni by priority.
     *
     * @param Builder $query
     * @return object
     */
 /*    private function getStatsQuery(Builder $query): object
    {
        return $query->selectRaw("
            SUM(CASE WHEN priority = 'bassa' THEN 1 ELSE 0 END) as bassa,
            SUM(CASE WHEN priority = 'media' THEN 1 ELSE 0 END) as media,
            SUM(CASE WHEN priority = 'alta' THEN 1 ELSE 0 END) as alta,
            SUM(CASE WHEN priority = 'urgente' THEN 1 ELSE 0 END) as urgente
        ")->first();
    } */

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
