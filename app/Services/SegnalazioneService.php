<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Models\Segnalazione;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SegnalazioneService
{
    /**
     * Get segnalazioni based on the user's role and associations.
     *
     * @param  \App\Models\Anagrafica|null  $anagrafica
     * @param  \Illuminate\Support\Collection|null  $condominioIds
     * @param  array  $validated
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getSegnalazioni(
        ?Anagrafica $anagrafica = null, 
        ?Collection $condominioIds = null,
        array $validated = []
    ){

        $user = Auth::user();

        return $user->hasRole(['amministratore', 'collaboratore']) || 
               $user->hasPermissionTo('Accesso pannello amministratore')
               ? $this->getAdminScopedQuery($validated)
               : $this->getUserScopedQuery($anagrafica, $condominioIds, $validated);

    }

    /**
     * Build and return a paginated list of published segnalazioni scoped to a regular user.
     *
     * The user can view segnalazioni that are:
     * - Associated with their anagrafica ID, OR
     * - Linked to their condomini (only if the segnalazione has no anagrafiche).
     *
     * If no valid anagrafica or condominio IDs are provided, an empty result set is returned.
     *
     * @param  \App\Models\Anagrafica|null  $anagrafica
     * @param  \Illuminate\Support\Collection|null  $condominioIds
     * @param  array  $validated  Optional filters: subject (string), priority (array), stato (array), per_page (int)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    private function getUserScopedQuery( 
        ?Anagrafica $anagrafica, 
        ?Collection $condominioIds, 
        array $validated
    ){
           // Validate that at least one filter is provided for non-admin users
        if (!$anagrafica || !$condominioIds) {
            Log::warning('No anagrafica or condominio IDs provided for user-scoped query.');
            return Segnalazione::query()->whereRaw('1 = 0')->paginate(1); // Return an empty result
        }

        return Segnalazione::with(['anagrafiche.user', 'condominio', 'createdBy.anagrafica'])
                ->where('is_published', true)
                ->where(function ($query) use ($anagrafica, $condominioIds) {
                    $query
                        ->where(function ($q) use ($anagrafica) {
                            $q->whereHas('anagrafiche', function ($sub) use ($anagrafica) {
                                $sub->where('anagrafica_id', $anagrafica->id);
                            });
                        })
                        ->orWhere(function ($q) use ($condominioIds) {
                            $q->whereIn('condominio_id', $condominioIds->toArray())
                                ->whereDoesntHave('anagrafiche');
                        });
                })
                ->when($validated['subject'] ?? false, fn($query, $subject) =>
                    $query->where('subject', 'like', "%{$subject}%")
                )
                ->when($validated['priority'] ?? false, fn($query, $priorities) =>
                    $query->whereIn('priority', $priorities)
                )
                ->when($validated['stato'] ?? false, fn($query, $stati) =>
                    $query->whereIn('stato', $stati)
                )
                ->orderBy('created_at', 'desc')
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString(); 
    }

    /**
     * Build and return a paginated list of segnalazioni for administrators or collaborators.
     *
     * Admins can view all segnalazioni, regardless of associations.
     * Supports optional filters such as subject, priority, stato, and pagination.
     *
     * @param  array  $validated  Optional filters: subject (string), priority (array), stato (array), per_page (int)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    private function getAdminScopedQuery(
        array $validated = []
    ){

        return Segnalazione::with(['createdBy', 'assignedTo', 'condominio'])
                ->when($validated['subject'] ?? false, function ($query, $subject) {
                    $query->where('subject', 'like', "%{$subject}%");
                })
                ->when($validated['priority'] ?? false, fn($query, $priorities) =>
                    $query->whereIn('priority', $priorities)
                )
                ->when($validated['stato'] ?? false, fn($query, $stati) =>
                    $query->whereIn('stato', $stati)
                )
                ->orderBy('created_at', 'desc')
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString(); 
    }
}