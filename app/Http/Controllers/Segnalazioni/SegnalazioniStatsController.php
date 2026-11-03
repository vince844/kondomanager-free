<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Segnalazione;
use App\Models\User;
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

            $query = $this->getUserScopedQuery($user);

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
     * Le segnalazioni che l'utente trova nell'elenco del portale, per contarle.
     *
     * La regola è `Segnalazione::scopeVisibiliNelPortale()`, la stessa della scheda e dell'elenco,
     * più «pubblicata e approvata» come l'elenco: i conteggi contano quello che l'elenco mostra, e
     * nient'altro: nessuna copia della regola qui. Una privata la contano solo l'autore e le persone
     * collegate, anche senza residenti (Coda 304), e per un utente senza anagrafica i conteggi non
     * vanno in errore.
     *
     * @return \Illuminate\Database\Eloquent\Builder  The scoped query for segnalazioni
     */
    private function getUserScopedQuery(User $user)
    {
        return Segnalazione::query()
            ->visibiliNelPortale($user)
            ->where('is_published', true)
            ->where('is_approved', true);
    }
}
