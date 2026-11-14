<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Il foglio del conguaglio non si stampa in blocco, e non è un guasto: le persone scelte sono più del tetto di un archivio, nessuna persona è fra
 * quelle scelte, il server non ha l'estensione zip. Il messaggio è per l'amministratore, e dice che cosa fare.
 */
class ConguaglioNonStampabileException extends Exception
{
}
