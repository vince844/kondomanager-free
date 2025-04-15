<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        return Inertia::render('dashboard/Dashboard', [
            'segnalazioni'  => SegnalazioneResource::collection(Segnalazione::limit(3)->latest()->get()),
            'comunicazioni'  => ComunicazioneResource::collection(Comunicazione::limit(3)->latest()->get()),
        ]);
    }
}
