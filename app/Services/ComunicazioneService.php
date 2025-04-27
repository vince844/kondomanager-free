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
     * Get comunicazioni based on the user's role and associations.
     *
     * @param  \App\Models\Anagrafica|null  $anagrafica
     * @param  \Illuminate\Support\Collection|null  $condominioIds
     * @param  array  $validated
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getComunicazioni(
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
     * Build and return a paginated list of published comunicazioni scoped to a regular user.
     *
     * The user can view comunicazioni that are:
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
    private function getuserScopedQuery(
        ?Anagrafica $anagrafica, 
        ?Collection $condominioIds, 
        array $validated
    ){

        // Validate that at least one filter is provided for non-admin users
        if (!$anagrafica || !$condominioIds) {
            Log::warning('No anagrafica or condominio IDs provided for user-scoped query.');
            return Comunicazione::query()->whereRaw('1 = 0')->paginate(1); // Return an empty result
        }

        return Comunicazione::with('anagrafiche', 'condomini', 'createdBy.anagrafica')
            ->where(function($query) use ($anagrafica, $condominioIds) {
                // Communications directly assigned to the user
                $query->whereHas('anagrafiche', function ($q) use ($anagrafica) {
                    $q->where('anagrafica_id', $anagrafica->id);
                });
                
                // OR communications assigned to condominios the user belongs to
                $query->orWhereHas('condomini', function ($q) use ($condominioIds) {
                    $q->whereIn('condominio_id', $condominioIds);
                });
            })
            ->when($validated['search'] ?? false, function ($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();
    }
    
    /**
    * Build and return a paginated list of comunicazioni for administrators or collaborators.
    *
    * Admins can view all comunicazioni, regardless of associations.
    * Supports optional filters such as subject, priority, stato, and pagination.
    *
    * @param  array  $validated  Optional filters: subject (string), priority (array), stato (array), per_page (int)
    * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
    */
    private function getAdminScopedQuery(
        array $validated = []
    ){

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
