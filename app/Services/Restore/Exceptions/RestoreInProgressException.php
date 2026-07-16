<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Un ripristino è già in corso, oppure c'è un backup in esecuzione:
 * ripristino e backup sono mutuamente esclusivi.
 */
class RestoreInProgressException extends RuntimeException {}
