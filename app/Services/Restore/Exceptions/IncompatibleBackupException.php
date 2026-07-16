<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Il backup non è ripristinabile su questa installazione: versione più
 * NUOVA del codice in esecuzione (downgrade non supportato), driver di
 * database non compatibile, formato manifest sconosciuto.
 */
class IncompatibleBackupException extends RuntimeException {}
