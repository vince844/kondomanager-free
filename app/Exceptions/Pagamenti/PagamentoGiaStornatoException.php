<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Pagamenti;

/**
 * Tentativo di stornare un pagamento già stornato.
 * Il ledger è immutabile: uno storno già eseguito non può essere "de-stornato".
 * Per correggere: registrare un nuovo pagamento.
 *
 * HTTP: 409 Conflict
 */
class PagamentoGiaStornatoException extends PagamentoException {}
