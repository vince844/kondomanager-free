<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\PerLePagine;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * L'avviso della tabella parziale nella finestra del punto (decisione 640 (2)): per ogni tabella e ogni perimetro le scelte delle pagine dicono quante unità
 * del perimetro hanno una quota e quante sono, perché la finestra scriva «La tabella ha 2 unità su 4: le altre 2 contano come teste a zero millesimi.».
 * Solo l'avviso: il conto della sala non cambia (la regola del condominio parziale viene dopo la ricerca di legge).
 *
 * Che cosa non copre: la frase della finestra (vitest, `FinestraDelPuntoTabellaParziale.test.ts`).
 */
it('le scelte dicono, per ogni tabella e perimetro, le unità con la quota e le unità del perimetro', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['1' => 250, '2' => 250, '3' => 250, '4' => 250])->tabella('Box', ['1' => 600, '2' => 400]);
    $tabelle = collect(PerLePagine::scelte($s->c)['tabelle'])->keyBy('nome');

    expect($tabelle['Box']['controlli']['condominio'])->toMatchArray(['copre' => false, 'con_quota' => 2, 'unita' => 4,
        'frase' => '2 unità su 4 · 1.000,00 millesimi'])
        ->and($tabelle['Proprietà']['controlli']['condominio'])->toMatchArray(['copre' => true, 'con_quota' => 4, 'unita' => 4]);
});
