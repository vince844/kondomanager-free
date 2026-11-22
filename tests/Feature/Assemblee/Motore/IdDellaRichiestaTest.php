<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\TabellaDiProprieta;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R18 del progetto della tappa 2; §13 rilievo 12): un id che arriva nella richiesta è del condominio della rotta. Il risolutore
 * solo dà la scheda di Via Roma 12 e un 404 per quella di Corso Italia 3, per ogni tipo dell'elenco, e per un tipo che l'elenco non conosce; il memo con
 * l'origine e l'assemblea di un altro condominio, la tabella di proprietà di un altro condominio, «Scegli la delibera» con una scheda fuori: 404.
 *
 * Che cosa non copre: le rotte delle pagine, che passano da qui (le prova chi fa le pagine, una per rotta).
 */

it('la scheda di Via Roma si risolve; quella di Corso Italia 3 e un tipo sconosciuto sono 404', function () {
    $via = ViaRoma12::nuovo($this);
    $corso = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $fattura = ViaRoma12::fatturaInSforo($via);
    $altra = ViaRoma12::fatturaInSforo($corso, '7');
    $persona = $via->persona('ugo');
    $delibera = ViaRoma12::delibera($corso, '2026-10-22', 'Il cancello');

    expect(SchedaDelCondominio::risolvi($via->c, 'fattura_passiva', $fattura->id)->is($fattura))->toBeTrue()
        ->and(SchedaDelCondominio::risolvi($via->c, 'anagrafica', $persona->id)->is($persona))->toBeTrue()
        ->and(fn () => SchedaDelCondominio::risolvi($via->c, 'fattura_passiva', $altra->id))->toThrow(NotFoundHttpException::class)
        ->and(fn () => SchedaDelCondominio::risolvi($corso->c, 'anagrafica', $persona->id))->toThrow(NotFoundHttpException::class)
        ->and(fn () => SchedaDelCondominio::risolvi($via->c, 'delibera', $delibera->id))->toThrow(NotFoundHttpException::class)
        ->and(fn () => SchedaDelCondominio::risolvi($via->c, 'punto', $delibera->punto_id))->toThrow(NotFoundHttpException::class)
        ->and(fn () => SchedaDelCondominio::risolvi($via->c, 'classe_php', 1))->toThrow(NotFoundHttpException::class);
});

it('il memo, la tabella di proprietà e «Scegli la delibera» rifiutano un id di un altro condominio', function () {
    $via = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 1000]);
    $corso = ViaRoma12::nuovo($this, 'Corso Italia 3')->tabella('Proprietà', ['A' => 1000]);
    $altra = ViaRoma12::fatturaInSforo($corso, '7');
    $assemblea = ViaRoma12::ordinaria($corso);

    expect(fn () => MemoriaDelCondominio::salva($via->c, ['testo' => 'X', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $altra->id], $via->admin))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => MemoriaDelCondominio::salva($via->c, ['testo' => 'X', 'assemblea_id' => $assemblea->id], $via->admin))->toThrow(NotFoundHttpException::class)
        ->and(fn () => TabellaDiProprieta::scegli($via->c, $corso->tabelle['Proprietà']))->toThrow(NotFoundHttpException::class)
        ->and(fn () => \App\Services\Assemblee\DelibereDelCondominio::scegli($via->c, 'fattura_passiva', $altra->id, 'ratifica', null))
            ->toThrow(NotFoundHttpException::class);
});
