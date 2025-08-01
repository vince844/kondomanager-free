<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Segnalazione;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request and return the dashboard view.
     *
     * This method retrieves the latest 3 Segnalazione and Comunicazione records,
     * along with their related models, and passes them to the 'dashboard/Dashboard' Inertia view.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard/Dashboard', [
            'segnalazioni'  => SegnalazioneResource::collection(
                Segnalazione::with([
                    'createdBy.anagrafica',
                    'assignedTo',
                    'condominio',
                    'anagrafiche',
                ])->limit(3)->latest()->get()
            ),
            'comunicazioni' => ComunicazioneResource::collection(
                Comunicazione::with([
                    'createdBy.anagrafica',
                    'condomini',
                    'anagrafiche',
                ])->limit(3)->latest()->get()
            ),
            'documenti' => DocumentoResource::collection(
                Documento::with([
                    'createdBy.anagrafica',
                    'condomini',
                    'anagrafiche',
                    'categoria',
                ])->limit(3)->latest()->get()
            ),
        ]);
    }
}
