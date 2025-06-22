<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Segnalazione;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SegnalazioneService
{
    /**
     * Get paginated segnalazioni depending on user role.
     *
     * @param Anagrafica|null $anagrafica
     * @param Collection|null $condominioIds
     * @param array $validated
     * @return LengthAwarePaginator
     */
    public function getSegnalazioni(
        ?Anagrafica $anagrafica = null,
        ?Collection $condominioIds = null,
        array $validated = []
    ): LengthAwarePaginator {
        return $this->isAdmin()
            ? $this->getScopedQuery(null, null, $validated, true)
            : $this->getScopedQuery($anagrafica, $condominioIds, $validated, false);
    }

    /**
     * Apply filters and return the scoped query.
     *
     * @param Anagrafica|null $anagrafica
     * @param Collection|null $condominioIds
     * @param array $validated
     * @param bool $isAdmin
     * @return LengthAwarePaginator
     */
    private function getScopedQuery(?Anagrafica $anagrafica, ?Collection $condominioIds, array $validated, bool $isAdmin): LengthAwarePaginator
    {
        $query = $isAdmin 
            ? $this->buildAdminBaseQuery()
            : $this->buildUserScopedBaseQuery($anagrafica, $condominioIds);

        return $query
            ->when($validated['search'] ?? false, fn($q, $search) =>
                $q->where('subject', 'like', "%{$search}%")
            )
            ->when($validated['subject'] ?? false, fn($q, $subject) =>
                $q->where('subject', 'like', "%{$subject}%")
            )
            ->when($validated['priority'] ?? false, fn($q, $priorities) =>
                $q->whereIn('priority', $priorities)
            )
            ->when($validated['stato'] ?? false, fn($q, $stati) =>
                $q->whereIn('stato', $stati)
            )
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->withQueryString();
    }

    /**
     * Build base query for a regular user based on their anagrafica and condomini.
     *
     * @param Anagrafica|null $anagrafica
     * @param Collection|null $condominioIds
     * @return Builder
     */
    private function buildUserScopedBaseQuery(?Anagrafica $anagrafica, ?Collection $condominioIds): Builder
    {
        if (!$anagrafica || !$condominioIds || $condominioIds->isEmpty()) {
            Log::warning('No anagrafica or condominio IDs provided for user-scoped query.');
            return Segnalazione::query()->whereRaw('1 = 0'); 
        }

        return Segnalazione::with(['anagrafiche.user', 'condominio', 'createdBy.anagrafica'])
            ->where('is_published', true)
            ->where('is_approved', true)
            ->where(function ($query) use ($anagrafica, $condominioIds) {
                $query
                    ->whereHas('anagrafiche', fn($sub) =>
                        $sub->where('anagrafica_id', $anagrafica->id)
                    )
                    ->orWhere(fn($q) =>
                        $q->whereIn('condominio_id', $condominioIds->toArray())
                          ->whereDoesntHave('anagrafiche')
                    );
            });
    }

    /**
     * Build base query for admin users.
     *
     * @return Builder
     */
    private function buildAdminBaseQuery(): Builder
    {
        return Segnalazione::with(['anagrafiche.user', 'createdBy', 'assignedTo', 'condominio']);
    }

    /**
     * Get statistics for segnalazioni based on user role.
     *
     * @return object
     */
    public function getSegnalazioniStats(): object
    {
        $user = Auth::user();

        $isAdmin = $user->hasRole(['amministratore', 'collaboratore']) ||
                   $user->hasPermissionTo('Accesso pannello amministratore');

        $query = $isAdmin
            ? $this->buildAdminBaseQuery()
            : $this->buildUserScopedBaseQuery($user->anagrafica, optional($user->anagrafica)->condomini->pluck('id') ?? collect());

        return $this->buildStatsQuery($query);
    }

    /**
     * Build aggregated stats for segnalazioni priorities.
     *
     * @param Builder $query
     * @return object
     */
    private function buildStatsQuery(Builder $query): object
    {
        return $query->selectRaw("
            SUM(CASE WHEN priority = 'bassa' THEN 1 ELSE 0 END) as bassa,
            SUM(CASE WHEN priority = 'media' THEN 1 ELSE 0 END) as media,
            SUM(CASE WHEN priority = 'alta' THEN 1 ELSE 0 END) as alta,
            SUM(CASE WHEN priority = 'urgente' THEN 1 ELSE 0 END) as urgente
        ")->first();
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
