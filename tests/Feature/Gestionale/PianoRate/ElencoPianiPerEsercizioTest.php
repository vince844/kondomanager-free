<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'elenco dei piani rate di un esercizio mostra i piani di quell'esercizio. La gestione ordinaria si aggancia a ogni esercizio, e l'elenco filtrava per
 * la gestione: nel 2027 compariva il «Preventivo 2026», e il suo collegamento apriva la pagina con l'esercizio sbagliato. Un piano senza l'esercizio salvato
 * (dei dati di prima che la colonna esistesse) sta nell'esercizio che dice `CompetenzaDelPiano::esercizioDelPiano`, e solo lì.
 */

use App\Models\Esercizio;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il «Preventivo 2026» nell'esercizio 2026, e l'esercizio 2027 con la stessa gestione ordinaria agganciata. */
function b15epScenario($test): array
{
    $s = b52tScenario($test);
    $s['piano'] = b52tPiano($test, $s);
    $s['e27'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    app(CondominioService::class)->createDefaultGestione($s['c'], $s['e27']);
    expect($s['g']->fresh()->esercizi()->pluck('esercizi.id')->all())->toContain($s['e27']->id);

    return $s;
}

function b15epNomi($test, array $s, Esercizio $e): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-rate.index', [$s['c'], $e]));
    $r->assertOk();

    return collect($r->viewData('page')['props']['pianiRate'])->pluck('nome')->all();
}

it('l\'elenco del 2027 non ha il «Preventivo 2026»; quello del 2026 sì', function () {
    $s = b15epScenario($this);

    expect(b15epNomi($this, $s, $s['e27']))->not->toContain('Preventivo 2026')
        ->and(b15epNomi($this, $s, $s['e']))->toContain('Preventivo 2026');
});

it('un piano senza l\'esercizio salvato esce solo nell\'esercizio che gli dà la competenza', function () {
    $s = b15epScenario($this);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['esercizio_id' => null, 'created_at' => '2026-03-01 10:00:00']);

    expect(b15epNomi($this, $s, $s['e']))->toContain('Preventivo 2026')
        ->and(b15epNomi($this, $s, $s['e27']))->not->toContain('Preventivo 2026');
});

it('la pagina di un piano aperta con un altro esercizio porta a quella del suo esercizio', function () {
    $s = b15epScenario($this);

    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e27'], $s['piano']]))
        ->assertRedirect(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]));
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]))->assertOk();
});
