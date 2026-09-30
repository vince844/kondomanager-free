<?php

namespace App\Http\Controllers\Condomini;

use App\Http\Controllers\Controller;
use App\Models\Condominio;

class FetchCondominiController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * L'accesso è già ristretto dalla rotta (giro di sicurezza della 1.11.0-beta.39): solo
     * amministratore, collaboratore o chi ha l'accesso al pannello o il permesso diretto
     * «Visualizza condomini» arrivano fin qui, esattamente come `comuni.cerca`. Chi ci arriva vede
     * sempre l'elenco intero — è lo stesso criterio «l'amministratore vede tutto» già usato altrove
     * (`SegnalazioniStatsController`, `ComunicazioneService`, `RedirectHelper`), e un ruolo
     * personalizzato con solo l'accesso al pannello non perde il menu dei condomìni sulla dashboard.
     */
    public function __invoke()
    {
        $condomini = Condominio::select('id', 'nome')->orderBy('nome')->get();

        return response()->json($condomini);
    }
}
