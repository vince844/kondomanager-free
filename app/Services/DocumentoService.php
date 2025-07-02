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

        return $query->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
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
        return Documento::with(['createdBy', 'condomini', 'anagrafiche', 'categoria'])
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

        return Documento::with(['anagrafiche', 'condomini', 'createdBy.anagrafica', 'categoria'])
            ->where('is_published', true)
            ->where('is_approved', true)
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
            ->when($validated['category_id'] ?? false, fn($q, $c) => $q->whereIn('category_id', $c));
    }

    /**
     * Get counts grouped by category without ordering (fixes MySQL ONLY_FULL_GROUP_BY issue).
     */
    public function getUserDocumentCountsByCategoria(Anagrafica $anagrafica, Collection $condominioIds): Collection
    {
        $query = $this->getUserBaseQuery($anagrafica, $condominioIds);

        $query->getQuery()->orders = null; // Remove orderBy to avoid SQL error

        return $query->selectRaw('category_id, COUNT(*) as count')
                     ->groupBy('category_id')
                     ->pluck('count', 'category_id');
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
        $query = $this->getScopedBaseQuery($anagrafica, $condominioIds, $validated)
                      ->where('category_id', $categoriaId);

        return $query->orderBy('created_at', 'desc')
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
        $query = Documento::query();

        return (object) [
            'total_storage_bytes' => (int) $query->sum('file_size'),
            'total_documents' => (int) $query->count(),
            'uploaded_this_month' => (int) $query->whereMonth('created_at', now()->month)
                                                 ->whereYear('created_at', now()->year)
                                                 ->count(),
            'average_size_bytes' => (float) $query->avg('file_size') ?: 0,
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
                'total_documents' => 0,
                'uploaded_this_month' => 0,
                'average_size_bytes' => 0,
            ];
        }

        $query = $this->getUserBaseQuery($anagrafica, $condominioIds);

        return (object) [
            'total_storage_bytes' => (int) $query->sum('file_size'),
            'total_documents' => (int) $query->count(),
            'uploaded_this_month' => (int) $query->whereMonth('created_at', now()->month)
                                                ->whereYear('created_at', now()->year)
                                                ->count(),
            'average_size_bytes' => (float) $query->avg('file_size') ?: 0,
        ];
    }

    /**
     * Check if current user is admin or collaborator.
     */
    protected function isAdmin(): bool
    {
        $user = Auth::user();
        return $user->hasRole(['amministratore', 'collaboratore']) ||
               $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value);
    }
}
