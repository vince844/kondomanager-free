<?php

namespace App\Services\Backup\Exceptions;

use RuntimeException;

/**
 * Lanciata quando si tenta di avviare un backup mentre un altro è già in
 * esecuzione. Il controller la traduce in una risposta 409.
 */
class BackupInProgressException extends RuntimeException {}
