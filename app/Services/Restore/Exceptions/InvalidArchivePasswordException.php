<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * L'archivio è cifrato e la password fornita non lo apre (ZipArchive non
 * lancia: restituisce false in lettura — questa eccezione dà al caso un
 * nome e un messaggio utile all'utente).
 */
class InvalidArchivePasswordException extends RuntimeException {}
