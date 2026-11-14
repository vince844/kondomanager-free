<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * I fogli del conguaglio non partono per email, e non è un guasto: manca il server di posta, l'oggetto o il testo, nessun indirizzo è scelto,
 * nessuna persona scelta ne ha uno. Il messaggio è per l'amministratore, e dice che cosa fare.
 */
class FogliNonInviatiException extends Exception
{
}
