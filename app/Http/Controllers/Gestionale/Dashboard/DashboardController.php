<?php

namespace App\Http\Controllers\Gestionale\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use HasCondomini, HasEsercizio;

    /**
     * Handle the incoming request.
     */
    public function __invoke(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/dashboard/Dashboard', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'esercizio'  => $esercizio
        ]);
    }
}
