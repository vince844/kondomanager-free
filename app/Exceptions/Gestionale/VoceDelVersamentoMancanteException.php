<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

/**
 * Un incasso che chiude interessi o spese addebitati in un esercizio e versati in un altro: il denaro va alla voce con lo stesso nome nel piano dei conti
 * dell'esercizio del versamento, perché il suo rendiconto lo legga e lo divida. Se quell'esercizio non ha ancora il piano dei conti di una gestione dello
 * stesso tipo, la voce non ha dove stare: l'incasso non si registra, e la frase dice che cosa fare.
 */
class VoceDelVersamentoMancanteException extends IncassoNonRegistrabileException
{
    public function campo(): string
    {
        return 'dettaglio_pagamenti';
    }
}
