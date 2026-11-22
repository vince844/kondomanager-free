<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

/** Il testo del verbale non è nella struttura dell'editor: la frase dice perché ({@see StrutturaDelVerbale}). */
final class StrutturaNonValida extends \RuntimeException {}
