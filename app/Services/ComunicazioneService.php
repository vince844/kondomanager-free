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

        return $user->hasRole(['amministratore', 'collaboratore'])
        ? $this->getAdminScopedQuery($validated)
        : $this->getUserScopedQuery($anagrafica, $condominioIds, $validated);

    }

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

        return Comunicazione::with('anagrafiche', 'condomini')
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
