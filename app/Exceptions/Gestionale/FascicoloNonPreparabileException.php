<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Il fascicolo per il legale non si prepara, e non è un guasto del programma: il server non ha l'estensione zip, l'archivio non si scrive sulla
 * cartella temporanea. Il messaggio è per l'amministratore, e dice che cosa fare.
 */
class FascicoloNonPreparabileException extends Exception
{
}
