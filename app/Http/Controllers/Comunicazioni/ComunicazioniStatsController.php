<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Models\Comunicazione;
use Illuminate\Support\Facades\Auth;

class ComunicazioniStatsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke()
    {
        $user = Auth::user();

        if($user->hasRole(['amministratore', 'collaboratore']) || $user->hasPermissionTo('Accesso pannello amministratore')) {

            $counts = Comunicazione::selectRaw("
                SUM(CASE WHEN priority = 'bassa' THEN 1 ELSE 0 END) as bassa,
                SUM(CASE WHEN priority = 'media' THEN 1 ELSE 0 END) as media,
                SUM(CASE WHEN priority = 'alta' THEN 1 ELSE 0 END) as alta,
                SUM(CASE WHEN priority = 'urgente' THEN 1 ELSE 0 END) as urgente
            ")->first();

            return response()->json($counts);
           
        } else {

            $anagrafica = $user->anagrafica;
            $condominioIds = $anagrafica->condomini->pluck('id');

            $query = $this->getUserScopedQuery($anagrafica, $condominioIds);

            $counts = $query->selectRaw("
                SUM(CASE WHEN priority = 'bassa' THEN 1 ELSE 0 END) as bassa,
                SUM(CASE WHEN priority = 'media' THEN 1 ELSE 0 END) as media,
                SUM(CASE WHEN priority = 'alta' THEN 1 ELSE 0 END) as alta,
                SUM(CASE WHEN priority = 'urgente' THEN 1 ELSE 0 END) as urgente
            ")->first();

            return response()->json($counts);
        
        }
  
    }

    /**
     * Get a query scoped to the user's anagrafica and associated condominios.
     *
     * This method builds a query to filter segnalazioni based on whether they are 
     * associated with the user's anagrafica or if the segnalazione belongs to any of 
     * the user's condominios but is not linked to any anagrafica.
     *
     * @param $anagrafica  The anagrafica of the currently authenticated user
     * @param $condominioIds  The IDs of the condominios associated with the user's anagrafica
     * 
     * @return \Illuminate\Database\Eloquent\Builder  The scoped query for segnalazioni
     */
    private function getUserScopedQuery($anagrafica, $condominioIds)
    {
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
            });
    }
}
