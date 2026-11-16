<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Una rata con un capitale oltre la misura del calcolo degli interessi (un contributo di € 20.000.000,00 scritto per errore al posto di € 200,00) si ferma
 * da sola, con la frase: l'elenco «Rate scadute» del condominio si apre, e la proposta degli interessi dice le altre rate con le loro cifre. Sul caso di
 * Neri di Via Verdi (`AccessoriScenario.php`): rata 1 di € 300,00 scaduta il 31/03/2026, tasso legale 2026 1,60 %.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

it('un contributo enorme scaduto non rompe l\'elenco «Rate scadute» né la proposta: si ferma solo la sua rata, con la frase', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-01 10:00:00');
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '20.000.000,00', 'natura' => 'contributo', 'scadenza' => '2026-04-01']])->assertCreated();

    $this->travelTo('2026-05-01 10:00:00');
    $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', [$s['c']]))->assertOk();
    $neri = accDi(accProposta($this, $s, ['Neri'])->assertOk()->json(), $s, 'Neri');
    $fermi = array_values(array_filter($neri['righe'], fn ($r) => $r['fermo'] !== null));

    expect($fermi)->toHaveCount(1)
        ->and($fermi[0]['fermo'])->toContain('fuori misura')
        // La rata 1 resta con la sua cifra: 300,00 × 1,60 % × 31/365 (dal 01/04 al 01/05) = 0,407671 → € 0,41.
        ->and($neri['totale'])->toBe(41);
});
