<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Il fascicolo per il legale non si prepara, e non è un guasto del programma: il server non ha l'estensione zip, l'archivio non si scrive sulla
 * cartella temporanea. Il messaggio è per l'amministratore, e dice che cosa fare.
 */
class FascicoloNonPreparabileException extends Exception
{
}
