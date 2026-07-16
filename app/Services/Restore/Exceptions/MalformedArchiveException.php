<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * L'archivio contiene qualcosa che un backup di KondoManager non può
 * contenere: percorsi traversal (zip-slip), symlink, voci fuori
 * dall'allowlist, manifest illeggibile. Non è "un file in più": è un
 * archivio manomesso o estraneo, e il ripristino si ferma PRIMA di
 * estrarre qualsiasi cosa di sospetto.
 */
class MalformedArchiveException extends RuntimeException {}
