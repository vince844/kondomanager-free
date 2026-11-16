<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Il foglio del conguaglio non si stampa in blocco, e non è un guasto: le persone scelte sono più del tetto di un archivio, nessuna persona è fra
 * quelle scelte, il server non ha l'estensione zip. Il messaggio è per l'amministratore, e dice che cosa fare.
 */
class ConguaglioNonStampabileException extends Exception
{
}
