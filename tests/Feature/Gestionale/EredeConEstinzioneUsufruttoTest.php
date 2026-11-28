<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 776 — l'erede con un'estinzione dell'usufrutto registrata prima del decesso del defunto.
 *
 * La richiesta del passaggio ferma un erede con una successione registrata prima del decesso del defunto (Coda 485). La morte dell'usufruttuario si
 * registra come estinzione dell'usufrutto, che non dice la causa (l'usufrutto finisce anche per rinuncia o per termine): fermare sarebbe sbagliato per
 * una persona viva. Il pannello avvisa, senza fermare; `kondomanager:verifica-titolarita` elenca le successioni già scritte così.
 *
 * Il caso: Ada usufruttuaria dell'Interno 2 (Nina nuda proprietaria), estinzione dell'usufrutto registrata il 01/03/2026; Ugo, proprietario dell'Interno A,
 * muore il 01/05/2026 e la successione nomina Ada erede.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-06-15 10:00:00');
});

const EEU_AVVISO = 'Ada ha un\'estinzione dell\'usufrutto registrata il 1 marzo 2026 su Interno 2. Se l\'usufrutto si è estinto per la sua morte, non può essere fra gli eredi di Ugo: indica gli eredi come li dice la dichiarazione di successione.';

function eeuScenario($test, string $estinzione = '2026-03-01'): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 500, '2' => 500])->titolare('A', 'ugo')->titolare('2', 'ada', 'usufruttuario')->titolare('2', 'nina', 'nuda_proprietario');
    $s->passaggio('estinzione', '2', 'ada', null, $estinzione);

    return $s;
}

function eeuAnteprima($test, Beta51Scenario $s, array $eredi = ['ada' => 50, 'bruno' => 50])
{
    $dati = $s->modulo('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => $eredi, 'arretrato' => 'eredi', 'riferimento' => array_key_first($eredi)]);

    return $test->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s->c, $s->unita['A']]), $dati);
}

it('Coda 776 — Ada con l\'estinzione dell\'usufrutto del 01/03 fra gli eredi di Ugo, morto il 01/05: il pannello avvisa, con l\'unità e la data, e non ferma il passaggio', function () {
    $s = eeuScenario($this);

    $r = eeuAnteprima($this, $s)->assertOk();

    expect($r->json('cancello.avvisi'))->toContain(EEU_AVVISO);
    // L'avviso non ferma: la registrazione passa.
    $successione = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['ada' => 50, 'bruno' => 50], 'arretrato' => 'eredi', 'riferimento' => 'ada']);
    expect($successione->tipo_passaggio)->toBe('successione');
});

it('Coda 776, guardie — l\'estinzione dopo il decesso del defunto, o un erede senza estinzione, non porta l\'avviso', function () {
    $s = eeuScenario($this, '2026-05-10');
    expect(eeuAnteprima($this, $s)->assertOk()->json('cancello.avvisi'))->not->toContain(EEU_AVVISO);

    $t = eeuScenario($this);
    $avvisi = eeuAnteprima($this, $t, ['bruno' => 100])->assertOk()->json('cancello.avvisi');
    expect(collect($avvisi)->filter(fn ($a) => str_contains($a, 'estinzione dell\'usufrutto'))->all())->toBe([]);
});

it('Coda 776 — `kondomanager:verifica-titolarita` elenca la successione già scritta con l\'erede che ha un\'estinzione dell\'usufrutto di prima', function () {
    $s = eeuScenario($this);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['ada' => 50, 'bruno' => 50], 'arretrato' => 'eredi', 'riferimento' => 'ada']);

    Artisan::call('kondomanager:verifica-titolarita', ['--condominio' => $s->c->id]);
    $out = Artisan::output();

    expect($out)->toContain('Eredi con un\'estinzione dell\'usufrutto prima del decesso del defunto (1)')
        ->toContain('Ugo')->toContain('01/05/2026')->toContain('Ada')->toContain('01/03/2026, estinzione su Interno 2');
    expect(Subentro::count())->toBe(2);
});
