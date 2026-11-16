<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * L'archivio è cifrato e la password fornita non lo apre (ZipArchive non
 * lancia: restituisce false in lettura — questa eccezione dà al caso un
 * nome e un messaggio utile all'utente).
 */
class InvalidArchivePasswordException extends RuntimeException {}
