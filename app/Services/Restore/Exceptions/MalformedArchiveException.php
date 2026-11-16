<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
