<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support;

use App\Helpers\MoneyHelper;

/**
 * Una cifra con il segno si dice con le parole: «€ 90,74», e per un credito «€ 29,26 di credito» o «un credito di € 29,26», mai «€ -29,26».
 *
 * È la formula che le frasi dei passaggi usano in tre punti (le fermate dopo «Scrivi», il conguaglio, il pannello): una sola, perché un credito
 * detto in due modi diversi nella stessa schermata è il difetto che la Coda 251-c chiude. Centesimi, segno positivo = debito.
 */
final class ImportoInParole
{
    /** «€ 90,74», o per una cifra negativa «€ 29,26 di credito»: per le frasi con il verbo al plurale («… sono rimasti»). */
    public static function soldi(int $cifra): string
    {
        return MoneyHelper::format(abs($cifra)) . ($cifra < 0 ? ' di credito' : '');
    }

    /** «€ 19,40», o per una cifra negativa «un credito di € 19,40». */
    public static function cifraInParole(int $cifra): string
    {
        return $cifra < 0 ? 'un credito di ' . MoneyHelper::format(-$cifra) : MoneyHelper::format($cifra);
    }
}
