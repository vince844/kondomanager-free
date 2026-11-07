<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Exceptions\Gestionale\RicevutaNonEmettibileException;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\Gestionale\Ricevute\AvvisiCompensazione;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Gli avvisi di compensazione, dalla sezione «Compensazioni senza avviso» della pagina delle ricevute: la stampa in un PDF e l'invio per email.
 * Le compensazioni scelte («incasso:persona») devono essere di questo condominio e ancora compensazioni: altrimenti 404 per tutta la richiesta.
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello.
 */
class AvvisiCompensazioneController extends Controller
{
    public function __construct(private readonly AvvisiCompensazione $avvisi) {}

    /** La stampa: un avviso per pagina. Gli errori tornano come testo, perché la risposta si apre in una scheda del browser, come le ricevute. */
    public function stampa(Request $request, Condominio $condominio): Response
    {
        try {
            $esito = $this->avvisi->stampa($condominio, $request->input('avvisi'), $request->input('bollo'), $request->user());
        } catch (RicevutaNonEmettibileException $e) {
            return response('Avviso non stampato. ' . $e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($esito['pdf'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $esito['nome'] . '"');
    }

    /** L'invio per email agli indirizzi scelti (di serie la primaria e la secondaria). */
    public function invia(Request $request, Condominio $condominio): JsonResponse
    {
        try {
            return response()->json($this->avvisi->invia($condominio, $request->input('avvisi'), $request->input('bollo'), $request->input('indirizzi'), $request->user()));
        } catch (RicevutaNonEmettibileException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
