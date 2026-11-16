<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Backup\Exceptions;

use RuntimeException;

/**
 * Lanciata quando si tenta di avviare un backup mentre un altro è già in
 * esecuzione. Il controller la traduce in una risposta 409.
 */
class BackupInProgressException extends RuntimeException {}
