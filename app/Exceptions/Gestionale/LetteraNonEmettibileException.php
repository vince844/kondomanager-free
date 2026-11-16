<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * La lettera non si stampa, non si scarica o non si invia, e non è un guasto: il tipo non c'è, l'email è chiesta per una diffida, le persone
 * sono più del tetto di un PDF, manca il termine. Il messaggio è per l'amministratore, e dice che cosa fare.
 */
class LetteraNonEmettibileException extends Exception
{
}
