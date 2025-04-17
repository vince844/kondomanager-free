<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Models\Segnalazione;
use Illuminate\Http\Request;

class SegnalazioniStatsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $counts = Segnalazione::selectRaw("
            SUM(CASE WHEN priority = 'bassa' THEN 1 ELSE 0 END) as bassa,
            SUM(CASE WHEN priority = 'media' THEN 1 ELSE 0 END) as media,
            SUM(CASE WHEN priority = 'alta' THEN 1 ELSE 0 END) as alta,
            SUM(CASE WHEN priority = 'urgente' THEN 1 ELSE 0 END) as urgente
        ")->first();

        return response()->json($counts);
    }
}
