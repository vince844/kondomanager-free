<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Pagamenti;

/**
 * La ritenuta del pagamento è in una delega F24 confermata: lo storno, o la modifica della data o della ritenuta, si ferma finché
 * l'amministratore non la riporta in bozza (Coda 754 (10)). Il messaggio è quello di `DelegaF24::motivoBloccoDelPagamento()`.
 *
 * È una modifica vietata: in modifica la mostra la finestra di `modifica_vietata`, come le altre (`PagamentoFornitoreController::update`);
 * lo storno la prende per nome (`StornoPagamentoController`).
 *
 * HTTP: 422 Unprocessable Entity
 */
class RitenutaInDelegaConfermataException extends PagamentoModificaVietataException {}
