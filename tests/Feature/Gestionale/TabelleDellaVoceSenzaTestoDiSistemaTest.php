<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Associare, aggiornare e togliere una tabella di una voce del piano dei conti: un guasto del database non porta il suo testo in pagina (Coda 279).
 *
 * I tre controller passano l'eccezione da `MessaggioPerLaPagina`: la frase di una regola resta com'è («La somma delle percentuali deve essere
 * 100%»), un guasto diventa la frase fissa con il riferimento, senza `SQLSTATE` né nomi di tabelle. Il guasto si provoca togliendo la tabella
 * delle ripartizioni, che tutti e tre scrivono.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.debug' => false]);
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

function tvsGesto($test, array $s, string $azione, array $percentuali = ['percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0])
{
    $parametri = [$s['c'], $s['e'], $s['pc'], $s['generali']];

    return match ($azione) {
        'associa' => $test->actingAs($test->user)->from('/piano-dei-conti')->post(route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', $parametri),
            ['tabella_millesimale_id' => $s['box']->id, 'coefficiente' => 10] + $percentuali),
        'aggiorna' => $test->actingAs($test->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.aggiorna-tabella', [...$parametri, $s['tabella']]),
            ['coefficiente' => 60] + $percentuali),
        'dissocia' => $test->actingAs($test->user)->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.dissocia-tabella', [...$parametri, $s['tabella']])),
    };
}

it('un guasto del database dà la frase con il riferimento, senza il testo del database', function (string $azione) {
    $s = b53bvScenario($this, ['pulizie' => '']);
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['generali']->id)->update(['coefficiente' => 60]);
    Schema::drop('conto_tabella_ripartizioni');

    tvsGesto($this, $s, $azione)->assertRedirect('/piano-dei-conti');

    $messaggio = (string) (b53snFlash()['message'] ?? '');
    expect(b53snFlash()['type'] ?? null)->toBe('error')
        ->and($messaggio)->toContain('comunica all\'assistenza il riferimento')
        ->and($messaggio)->not->toContain('SQLSTATE')
        ->and($messaggio)->not->toContain('conto_tabella_ripartizioni');
})->with(['associa' => ['associa'], 'aggiorna' => ['aggiorna'], 'dissocia' => ['dissocia']]);

it('la frase di una regola resta com\'è', function (string $azione) {
    $s = b53bvScenario($this, ['pulizie' => '']);
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['generali']->id)->update(['coefficiente' => 60]);

    tvsGesto($this, $s, $azione, ['percentuale_proprietario' => 50, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0])->assertRedirect('/piano-dei-conti');

    expect(b53snFlash()['type'] ?? null)->toBe('error')
        ->and((string) (b53snFlash()['message'] ?? ''))->toContain('La somma delle percentuali deve essere 100%. Attuale: 50%');
})->with(['associa' => ['associa'], 'aggiorna' => ['aggiorna']]);
