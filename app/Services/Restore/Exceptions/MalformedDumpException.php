<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Il dump SQL non rispetta il formato prodotto da MySqlDumper: statement
 * senza terminatore, stringa mai chiusa, direttiva DELIMITER malformata.
 * In un ripristino equivale a "archivio corrotto o troncato": si interrompe
 * PRIMA di eseguire altro SQL, mai a metà di uno statement.
 */
class MalformedDumpException extends RuntimeException {}
