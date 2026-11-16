<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Mostra altri 15» nell'Action inbox chiede solo l'elenco (`only: tasks`): le letture contate della pagina (le card, i grafici, le schede, i gruppi) non si
 * rifanno. La prova conta le letture del database che passano dalla fascia dei compiti: la pagina intera le fa, la richiesta parziale no.
 */

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

it('«Mostra altri 15» chiede solo l\'elenco e non rifà le letture contate (card, grafici, schede, gruppi)', function () {
    foreach (range(1, 20) as $n) {
        icsCompito($this, 'emissione_rata', [], sprintf('Scaduto %02d', $n));
    }
    $contate = function (callable $richiesta): int {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });
        $richiesta();

        return count(array_filter($sql, fn ($s) => str_contains($s, 'fascia_compito')));
    };

    // La pagina intera le fa: la prova legge davvero le letture.
    expect($contate(fn () => $this->actingAs($this->admin)->get(route('admin.inbox'))->assertOk()))->toBeGreaterThan(0);

    $versione = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $parziale = $contate(fn () => $this->actingAs($this->admin)->get(route('admin.inbox', ['page' => 2]), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $versione, 'X-Inertia-Partial-Component' => 'dashboard/ActionInbox', 'X-Inertia-Partial-Data' => 'tasks',
    ])->assertOk());

    expect($parziale)->toBe(0);
});
