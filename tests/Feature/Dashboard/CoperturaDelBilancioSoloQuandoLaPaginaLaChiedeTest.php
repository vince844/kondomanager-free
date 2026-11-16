<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La copertura del bilancio della dashboard del condominio (`copertura`, `fattureScoperte`, `pianiDisallineati`) si calcola solo quando la pagina la chiede.
 *
 * - Un caricamento parziale che non la chiede (una scheda dell'Inbox operativa, lo scorrimento della lista, la finestra «Emetti le rate», le lettere) non la
 *   rifà: è una delle parti più lente della pagina.
 * - La pagina intera la ha ancora, e le tre prop sono le stesse di un caricamento parziale che le chiede.
 */

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function cbqParziale(string $prop): array
{
    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione, 'X-Inertia-Partial-Component' => 'gestionale/dashboard/Dashboard', 'X-Inertia-Partial-Data' => $prop];
}

/** Le letture della copertura: la somma degli addebiti personali sulle righe delle fatture dell'esercizio (la parte «spese ad personam» della copertura). */
function cbqLettureDellaCopertura(callable $fai): int
{
    $n = 0;
    DB::listen(function ($q) use (&$n) {
        if (str_contains($q->sql, 'righe_fattura') && str_contains($q->sql, 'immobile_id') && str_contains(strtolower($q->sql), 'sum(')) {
            $n++;
        }
    });
    $fai();

    return $n;
}

it('la scheda «Scadute» dell\'Inbox operativa (solo le prop dell\'Inbox) non rifà la copertura del bilancio', function () {
    $s = smaViaVerdi($this);
    $dashboard = route('admin.gestionale.index', $s['c']);

    // Il controllo: la pagina intera la calcola.
    expect(cbqLettureDellaCopertura(fn () => $this->actingAs($this->user)->get($dashboard)->assertOk()))->toBeGreaterThan(0);

    // La scheda: le quattro prop che chiede `scegliScheda()` in `Dashboard.vue`.
    $letture = cbqLettureDellaCopertura(fn () => $this->actingAs($this->user)
        ->get($dashboard.'?inbox=scaduti', cbqParziale('inboxTasks,inboxSchede,inboxScaduti,inboxScheda'))->assertOk());
    expect($letture)->toBe(0);
});

it('la finestra «Emetti le rate» (solo `emissioneRapida`) non rifà la copertura del bilancio', function () {
    $s = smaViaVerdi($this);
    $letture = cbqLettureDellaCopertura(fn () => $this->actingAs($this->user)
        ->get(route('admin.gestionale.index', $s['c']), cbqParziale('emissioneRapida'))->assertOk());
    expect($letture)->toBe(0);
});

it('la pagina intera ha la copertura, le fatture scoperte e i piani disallineati, uguali a quelli di un caricamento che li chiede', function () {
    $s = smaViaVerdi($this);
    $dashboard = route('admin.gestionale.index', $s['c']);
    $intera = $this->actingAs($this->user)->get($dashboard)->assertOk()->viewData('page')['props'];
    expect($intera)->toHaveKeys(['copertura', 'fattureScoperte', 'pianiDisallineati'])->and($intera['copertura'])->not->toBeNull();

    $parziale = $this->actingAs($this->user)->get($dashboard, cbqParziale('copertura,fattureScoperte,pianiDisallineati'))->assertOk()->json('props');
    expect(json_decode(json_encode($parziale['copertura']), true))->toEqual(json_decode(json_encode($intera['copertura']), true))
        ->and(json_decode(json_encode($parziale['fattureScoperte']), true))->toEqual(json_decode(json_encode($intera['fattureScoperte']), true))
        ->and(json_decode(json_encode($parziale['pianiDisallineati']), true))->toEqual(json_decode(json_encode($intera['pianiDisallineati']), true));
});
