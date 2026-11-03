<?php

namespace App\Http\Requests\Gestionale\Immobile\Anagrafica;

use App\Http\Requests\Anagrafica\CreateAnagraficaRequest;
use Illuminate\Auth\Access\Response;

/**
 * «Crea nuova anagrafica» dal modulo di «Registra passaggio» (`PassaggioController::creaAnagrafica()`).
 *
 * Le regole e la preparazione dei dati sono quelle dell'anagrafica del pannello, ereditate: un'email o un codice
 * fiscale già usati si fermano allo stesso modo. Cambia solo chi può: questa è una pagina del gestionale del
 * condominio, che si apre con l'accesso al pannello e non ha permessi propri (decisione 138.2); la richiesta del
 * pannello chiede invece «Crea anagrafiche» alla policy, prima delle regole.
 */
class CreaAnagraficaNelPassaggioRequest extends CreateAnagraficaRequest
{
    public function authorize(): Response
    {
        return Response::allow();
    }
}
