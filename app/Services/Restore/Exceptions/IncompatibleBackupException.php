<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Il backup non è ripristinabile su questa installazione: versione più
 * NUOVA del codice in esecuzione (downgrade non supportato), driver di
 * database non compatibile, formato manifest sconosciuto.
 */
class IncompatibleBackupException extends RuntimeException {}
