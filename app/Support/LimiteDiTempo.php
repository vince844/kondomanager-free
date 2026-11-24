<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support;

/**
 * Il limite di tempo del processo, alzato nel solo modo che non fa danni.
 *
 * Due trappole, una funzione sola per tutti i punti che lavorano a lungo (le stampe, il backup, il ripristino, l'aggiornamento dal pannello e le
 * migrazioni con dati):
 *
 *  - **Da riga di comando il limite vale 0**, cioè nessun limite. Un `set_time_limit(50)` secco lo ABBASSA a 50 secondi. Si alza quindi solo un
 *    limite finito e più basso di quello chiesto; un limite già illimitato o già più alto resta com'è.
 *  - **Dove l'hosting vieta `set_time_limit` con `disable_functions`**, in PHP 8 la funzione non esiste e chiamarla è un errore che `@` non tace: lì
 *    non si fa niente e il limite resta quello del processo.
 *
 * @param  int  $secondi  il limite voluto; 0 = nessun limite.
 */
final class LimiteDiTempo
{
    public static function alza(int $secondi): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        $corrente = (int) ini_get('max_execution_time');

        // Già illimitato: niente da alzare (e niente da abbassare).
        if ($corrente === 0) {
            return;
        }

        // Un limite finito già alto quanto basta resta com'è; con 0 (nessun limite) un limite finito si toglie sempre.
        if ($secondi !== 0 && $corrente >= $secondi) {
            return;
        }

        @set_time_limit($secondi);
    }
}
