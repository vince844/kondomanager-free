<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Un ripristino è già in corso, oppure c'è un backup in esecuzione:
 * ripristino e backup sono mutuamente esclusivi.
 */
class RestoreInProgressException extends RuntimeException {}
