<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Segnalazione;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SegnalazioniStatsController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * This method will retrieve statistical data on segnalazioni based on the user's role.
     * 
     * For an "amministratore" (administrator), the method calculates the count of segnalazioni 
     * for each priority level: 'bassa', 'media', 'alta', and 'urgente'.
     * 
     * For other users, the method filters segnalazioni based on the user's associated anagrafica 
     * and condominio (building), then calculates the same priority-based statistics.
     */
    public function __invoke(Request $request)
    {
        $user = Auth::user();

        if(
            $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]) || 
            $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)) 
        {

            $counts = Segnalazione::selectRaw("
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
     * Get a query scoped to the user's anagrafica and associated condomini.
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
        return Segnalazione::query()
            ->where('is_published', true)
            ->where('is_approved', true)
            ->where(function ($query) use ($anagrafica, $condominioIds) {
                $query
                    ->where(function ($q) use ($anagrafica) {
                        $q->whereHas('anagrafiche', function ($sub) use ($anagrafica) {
                            $sub->where('anagrafica_id', $anagrafica->id);
                        });
                    })
                    ->orWhere(function ($q) use ($condominioIds) {
                        $q->whereIn('condominio_id', $condominioIds)
                            ->whereDoesntHave('anagrafiche');
                    });
            });
    }
}
