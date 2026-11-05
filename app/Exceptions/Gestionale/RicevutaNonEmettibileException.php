<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * La ricevuta non si stampa o non si invia, e non è un guasto: manca la scelta del bollo, l'incasso è annullato, un destinatario non è fra
 * quelli proposti, le ricevute sono più del tetto di un PDF. Il messaggio è per l'amministratore, e dice che cosa fare.
 */
class RicevutaNonEmettibileException extends Exception
{
}
