<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\ControlloDelleDate;

/**
 * 1.12.0-beta.13 (Coda 679, R11 del progetto della tappa 2): il controllo delle date mentre si scrivono (`resources/js/lib/assemblee/date.ts`) e quello del
 * server provano **gli stessi casi**: la tabella `tests/Fixtures/casi_delle_date_assemblea.json`, che il test vitest della pagina legge, è uguale a
 * `ControlloDelleDate::CASI`, e il server dà su ogni caso la frase scritta lì.
 *
 * Che cosa non copre: il controllo della pagina (lo prova `date.test.ts` sulla stessa tabella).
 */

it('la tabella dei casi della pagina è quella del server, e il server dà la frase di ogni caso', function () {
    $tabella = json_decode((string) file_get_contents(base_path('tests/Fixtures/casi_delle_date_assemblea.json')), true, 512, JSON_THROW_ON_ERROR);
    $delServer = array_map(fn ($c) => ['che_cosa' => $c[0], 'sedute' => $c[1], 'valide' => $c[2], 'frase' => $c[3]], ControlloDelleDate::CASI);

    expect($tabella)->toBe($delServer)->and(count($tabella))->toBeGreaterThanOrEqual(10);
    foreach ($tabella as $caso) {
        expect(ControlloDelleDate::controlla($caso['sedute']))->toBe(['valide' => $caso['valide'], 'frase' => $caso['frase']], $caso['che_cosa']);
    }
});
