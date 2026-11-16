<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Pagamenti;

/**
 * Una o più fatture nelle allocazioni hanno stato_approvazione != 'approvata'.
 * Le fatture in stato di verifica/contestazione non sono pagabili.
 * Art. 1135 c.c. — l'assemblea deve deliberare le spese prima del pagamento.
 *
 * HTTP: 422 Unprocessable Entity
 */
class FatturaNonApprovataException extends PagamentoException {}
