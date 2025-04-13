<?php

namespace App\Services;

use App\Models\Segnalazione;
use Illuminate\Support\Facades\Auth;

class SegnalazioneService
{
    /**
     * Get segnalazioni based on the user's role and associations.
     *
     * @param  \App\Models\Anagrafica  $anagrafica
     * @param  \Illuminate\Support\Collection  $condominioIds
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSegnalazioni($anagrafica, $condominioIds)
    {
         // Get the currently authenticated user
         $user = Auth::user();
        
         // Start the query for Segnalazione
         $query = Segnalazione::with(['anagrafiche.user', 'condominio'])
         ->where('is_published', true)
         ->where(function ($query) use ($anagrafica, $condominioIds) {
             $query
                 // Case 1: Specifically assigned to the user's anagrafica
                 ->whereHas('anagrafiche', function ($q) use ($anagrafica) {
                     $q->where('anagrafica_id', $anagrafica->id);
                 })
                 // OR Case 2: Assigned to a condominio (user belongs to) and NOT to any specific anagrafiche
                 ->orWhere(function ($q) use ($condominioIds) {
                     $q->whereIn('condominio_id', $condominioIds)
                     ->whereDoesntHave('anagrafiche');
                 });
         })
         ->orderBy('created_at', 'desc');


        return $query->get();  
    }
}