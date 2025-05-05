<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ComunicazioneService
{
    /**
     * Retrieves a paginated list of communications based on the user's role and filters.
     *
     * If the authenticated user is an administrator or has access to the admin panel,
     * it returns a global (admin-scoped) query with optional filters.
     * Otherwise, it returns a user-scoped query limited to the user's anagrafica and condomini.
     *
     * @param \App\Models\Anagrafica|null $anagrafica The user's anagrafica (for user-scoped access)
     * @param \Illuminate\Support\Collection|null $condominioIds List of user's associated condominio IDs
     * @param array $validated Validated filter inputs (e.g., 'search', 'priority', 'per_page')
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator Paginated list of communications
     */
    public function getComunicazioni(
        ?Anagrafica $anagrafica = null,
        ?Collection $condominioIds = null,
        array $validated = []
    ) {
        $user = Auth::user();

        return $user->hasRole(['amministratore', 'collaboratore']) ||
               $user->hasPermissionTo('Accesso pannello amministratore')
            ? $this->getAdminScopedQuery($validated)
            : $this->getUserScopedQuery($anagrafica, $condominioIds, $validated);
    }

    /**
     * Builds a paginated user-scoped query for communications with optional filters.
     *
     * Uses the user's anagrafica and associated condominio IDs to scope the query,
     * then applies optional search filtering (subject or description) and pagination.
     * Eager loads relevant relationships and includes query string parameters in pagination links.
     *
     * @param \App\Models\Anagrafica|null $anagrafica The user's anagrafica instance
     * @param \Illuminate\Support\Collection|null $condominioIds Collection of user's condominio IDs
     * @param array $validated Validated filter input (e.g., 'search', 'per_page')
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator Paginated list of scoped communications
     */
    private function getUserScopedQuery(
        ?Anagrafica $anagrafica,
        ?Collection $condominioIds,
        array $validated
    ) {
        $query = $this->buildUserScopedBaseQuery($anagrafica, $condominioIds);

        return $query
            ->when($validated['search'] ?? false, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('subject', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();
    }

    /**
     * Public accessor for building a user-scoped base query for communications.
     *
     * Delegates to the internal `buildUserScopedBaseQuery` method to generate
     * a query builder filtered by the given anagrafica and associated condominio IDs.
     *
     * @param \App\Models\Anagrafica|null $anagrafica The user's anagrafica instance
     * @param \Illuminate\Support\Collection|null $condominioIds Collection of associated condominio IDs
     * @return \Illuminate\Database\Eloquent\Builder The user-scoped query builder for Comunicazione
     */
    public function getUserScopedBaseQuery(
        ?Anagrafica $anagrafica,
        ?Collection $condominioIds
    ) {
        return $this->buildUserScopedBaseQuery($anagrafica, $condominioIds);
    }

    /**
     * Builds a base query for user-scoped communications based on the given anagrafica
     * and associated condominio IDs.
     *
     * The query filters only published and approved communications and includes communications:
     * - Explicitly linked to the user's anagrafica, or
     * - Not linked to any anagrafiche but associated with one of the user's condomini.
     *
     * If no valid anagrafica or condominio IDs are provided, returns an empty query.
     * Eager loads related models: anagrafiche, condomini, and createdBy.anagrafica.
     *
     * @param \App\Models\Anagrafica|null $anagrafica The user's anagrafica instance
     * @param \Illuminate\Support\Collection|null $condominioIds Collection of associated condominio IDs
     * @return \Illuminate\Database\Eloquent\Builder The user-scoped query builder for Comunicazione
     */
    private function buildUserScopedBaseQuery(
        ?Anagrafica $anagrafica,
        ?Collection $condominioIds
    ) {
        if (!$anagrafica || !$condominioIds) {
            Log::warning('No anagrafica or condominio IDs provided for user-scoped query.');
            return Comunicazione::query()->whereRaw('1 = 0'); // Return empty query
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
     * Builds a paginated query for communications, scoped for administrators,
     * with optional filtering by subject and priority.
     *
     * Applies eager loading for related models (createdBy, condomini, anagrafiche),
     * supports filtering via the provided `$validated` input, and returns a paginated result.
     *
     * @param array $validated An array of validated filters (e.g., 'subject', 'priority', 'per_page')
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator Paginated list of communications
     */
    private function getAdminScopedQuery(array $validated = [])
    {
        return Comunicazione::with(['createdBy', 'condomini', 'anagrafiche'])
            ->when($validated['subject'] ?? false, function ($query, $subject) {
                $query->where('subject', 'like', "%{$subject}%");
            })
            ->when($validated['priority'] ?? false, fn($query, $priorities) =>
                $query->whereIn('priority', $priorities)
            )
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();
    }
}
