<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione;
use Carbon\CarbonImmutable;

/**
 * B5 (simulazione del 21/11/2026): «entro il 11/11» non si scrive. La funzione sola che compone un termine nella convocazione, nei suoi compiti e nei passi
 * mette l'articolo come si legge il numero: «l'1», «l'8», «l'11» (anche «l'08/12», «l'01/12», con l'anno «l'11/12/2026»), «il» per gli altri.
 *
 * Che cosa non copre: i punti che la chiamano (`StatoDellaConvocazione`, `PassiDellAssemblea`, i compiti): li copre la loro prova.
 */
it('mette «l\'» davanti a 1, 8 e 11 e «il» davanti agli altri', function (string $data, string $atteso) {
    expect(ConsegneDellaConvocazione::entro(CarbonImmutable::parse($data)))->toBe($atteso);
})->with([
    ['2026-11-11', 'entro l\'11/11'],
    ['2026-12-08', 'entro l\'08/12'],
    ['2026-12-01', 'entro l\'01/12'],
    ['2026-12-10', 'entro il 10/12'],
    ['2026-12-18', 'entro il 18/12'],
    ['2026-12-21', 'entro il 21/12'],
    ['2026-12-28', 'entro il 28/12'],
    ['2026-12-31', 'entro il 31/12'],
]);

it('con l\'anno e con il giorno già scritto', function () {
    expect(ConsegneDellaConvocazione::entro(CarbonImmutable::parse('2026-12-11'), 'd/m/Y'))->toBe('entro l\'11/12/2026')
        ->and(ConsegneDellaConvocazione::entro('08/12'))->toBe('entro l\'08/12')
        ->and(ConsegneDellaConvocazione::entro('03/12'))->toBe('entro il 03/12');
});
