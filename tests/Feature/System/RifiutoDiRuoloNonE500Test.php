<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il rifiuto di ruolo è un 403, non un 500 (Coda 147).
 *
 * Con il debug spento, come in produzione, un condòmino che apre una pagina del pannello, del gestionale o delle
 * impostazioni riceve 403: dal browser (HTML), da una chiamata che chiede JSON e da una visita di Inertia. Nessuna
 * riga nuova nel registro degli errori: un rifiuto non è un errore del programma.
 */

use App\Enums\Role as RoleEnum;
use App\Models\Condominio;
use App\Models\Errori\Errore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['app.debug' => false]);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);
    $this->condominio = Condominio::factory()->create();
});

dataset('pagine riservate', [
    'il pannello'      => fn () => '/admin/dashboard',
    'il gestionale'    => fn () => '/admin/gestionale/'.test()->condominio->id.'/esercizi',
    'le impostazioni'  => fn () => '/impostazioni/generali',
]);

it('il condòmino riceve 403 dal browser', function (string $url) {
    $this->actingAs($this->condomino)->get($url)->assertStatus(403);

    expect(Errore::count())->toBe(0);
})->with('pagine riservate');

it('il condòmino riceve 403 da una chiamata JSON', function (string $url) {
    $this->actingAs($this->condomino)->getJson($url)->assertStatus(403);

    expect(Errore::count())->toBe(0);
})->with('pagine riservate');

it('il condòmino riceve 403 da una visita di Inertia', function (string $url) {
    // La versione degli asset come la manda la pagina: senza, Inertia risponde 409 e il rifiuto non si vede.
    $versione = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

    $this->actingAs($this->condomino)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $versione, 'X-Requested-With' => 'XMLHttpRequest'])
        ->get($url)
        ->assertStatus(403);

    expect(Errore::count())->toBe(0);
})->with('pagine riservate');
