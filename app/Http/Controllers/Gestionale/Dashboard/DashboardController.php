<?php

namespace App\Http\Controllers\Gestionale\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use HasCondomini;

    /**
     * Handle the incoming request.
     */
    public function __invoke(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        return Inertia::render('gestionale/dashboard/Dashboard', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
        ]);
    }
}
