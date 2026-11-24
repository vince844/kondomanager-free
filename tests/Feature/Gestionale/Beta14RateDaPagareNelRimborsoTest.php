<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 227 (1.12.0-beta.14, solo la frase): l'estratto conto di una persona con un credito dal passaggio ancora nei saldi manda alla pagina anche quanto ha
 * ancora da pagare di rate (`rimborso.rate_da_pagare`, i residui aperti della persona nel condominio, la lettura di `LetturaDebiti::perPersona()` che usa anche
 * l'annullamento di un passaggio): la frase del riquadro, con rate aperte, dice che quel credito può andare prima su quelle, con «Compensa», e che si
 * rimborsa quello che avanza (fra le rate ci sono anche quelle emesse non ancora scadute: decide l'amministratore). Il caso è quello di `Beta53EstrattoLegataTest.php`: Ines esce dalla locazione con un credito legato di € 90,74 e ha ancora le sue rate emesse.
 *
 * Fonte dell'atteso: la somma dei residui delle quote emesse di Ines, letta dalla tabella delle quote (importo meno pagato), senza passare dalla lettura.
 *
 * Cosa NON copre: la frase a video (vitest `EstrattoContoCreditoInSaldi.test.ts`); la compensazione da sola (con la Coda 230, nella 1.13).
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Support/ScenariFineLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    sl53Amministratore($this);
});

it('Ines ha un credito legato di € 90,74 nei saldi e le sue rate emesse da pagare: la pagina riceve la somma dei residui aperti, in centesimi', function () {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $this->travel(1)->minutes();
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere'])->fresh();
    $this->travel(1)->minutes();
    expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone']['Ines']->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => -9074, 'passaggio_id' => $fine->id])))->toBe([]);

    $aperte = (int) DB::table('rate_quote')->where('anagrafica_id', $s['persone']['Ines']->id)->whereNotNull('scrittura_contabile_id')->whereColumn('importo_pagato', '<', 'importo')
        ->selectRaw('SUM(importo - importo_pagato) as residuo')->value('residuo');
    $rimborso = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['Ines']]))->assertOk()->viewData('page')['props']['rimborso'];

    expect($aperte)->toBeGreaterThan(0)
        ->and($rimborso['credito_in_saldi'])->toBe(9074)
        ->and($rimborso['rate_da_pagare'])->toBe($aperte);
});

it('senza rate aperte la somma è zero: la pagina dice la frase di sempre', function () {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclGenera($s);

    $rimborso = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['Ines']]))->assertOk()->viewData('page')['props']['rimborso'];

    // Nessuna rata emessa: niente da pagare.
    expect($rimborso['rate_da_pagare'])->toBe(0);
});
