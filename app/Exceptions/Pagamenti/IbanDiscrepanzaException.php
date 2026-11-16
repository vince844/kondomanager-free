<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Pagamenti;

/**
 * L'IBAN di destinazione inserito differisce dall'IBAN storico nell'anagrafica
 * del fornitore (Sentinella Anti-Frode).
 *
 * Bypassabile con flag iban_confermato_manualmente = true, che registra
 * nell'audit log chi ha confermato la discrepanza e quando.
 *
 * ATTENZIONE: le truffe Man-in-the-Middle con sostituzione IBAN su fatture PDF
 * sono il principale vettore di frode nel settore condominiale italiano.
 *
 * HTTP: 409 Conflict (con istruzioni per override)
 */
class IbanDiscrepanzaException extends PagamentoException {}
