<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Pagamenti;

/**
 * Nessun esercizio aperto disponibile per registrare lo storno cross-esercizio.
 * Si verifica quando l'esercizio del pagamento originale è chiuso E non esiste
 * un esercizio corrente aperto per quel condominio.
 *
 * HTTP: 409 Conflict
 */
class NessunEsercizioApertoException extends PagamentoException {}
