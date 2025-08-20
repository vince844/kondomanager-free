<?php

namespace App\Http\Controllers\Gestionale\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Condominio $condominio): Response
    {
        return Inertia::render('gestionale/dashboard/Dashboard', [
            'condominio' => $condominio,
        ]);
    }
}
