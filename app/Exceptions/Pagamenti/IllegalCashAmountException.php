<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Pagamenti;

/**
 * Violazione del limite contanti per normativa antiriciclaggio.
 * D.Lgs. 231/2007 — soglia corrente: 5.000 € (dal 01/01/2023).
 * Non bypassabile: il sistema non può essere aggirato su questo blocco
 * per proteggere l'amministratore da sanzioni amministrative.
 *
 * HTTP: 403 Forbidden
 */
class IllegalCashAmountException extends PagamentoException {}
