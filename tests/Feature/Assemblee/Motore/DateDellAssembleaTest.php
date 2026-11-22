<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\ControlloDelleDate;

/**
 * 1.12.0-beta.13 (Coda 679, R11 del progetto della tappa 2): la seconda convocazione in un giorno diverso e successivo alla prima, non oltre dieci giorni (art.
 * 1136 c. 3 c.c.; art. 66 disp. att., «non può tenersi nel medesimo giorno solare della prima»); le prosecuzioni dopo la data che proseguono. La tabella dei
 * casi è la stessa che usa il controllo della pagina.
 *
 * Che cosa non copre: il controllo della pagina in vitest (lo scrive chi fa la pagina, con gli stessi casi); il blocco dopo la prima spedizione
 * (`PreparazioneDellAssembleaTest`).
 */

it('ogni caso della tabella dà l\'esito e la frase scritti', function (string $caso, array $sedute, bool $valide, string $frase) {
    expect(ControlloDelleDate::controlla($sedute))->toBe(['valide' => $valide, 'frase' => $frase]);
})->with(array_combine(array_column(ControlloDelleDate::CASI, 0), ControlloDelleDate::CASI));

it('il messaggio della bozza per la seconda il giorno dopo la prima', function () {
    expect(ControlloDelleDate::controlla([['convocazione' => 'prima', 'fissata_il' => '2026-12-14T08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-15T18:30']]))
        ->toBe(['valide' => true, 'frase' => 'Un giorno diverso, un giorno dopo la prima: va bene.']);
});
