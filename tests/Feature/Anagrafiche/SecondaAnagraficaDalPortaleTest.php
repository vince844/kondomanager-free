<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il modulo «completa i tuoi dati» del portale crea l'anagrafica di chi non ce l'ha, una volta sola.
 *
 * Chi ha già un'anagrafica riceve il rifiuto (403) e la tabella resta con una riga sola per lui: niente seconda anagrafica, niente
 * condomini dell'invito ricollegati. Chi non ce l'ha la crea, come sempre.
 */

use App\Enums\Role as RoleEnum;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Invito;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function condominoDelPortale(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(RoleEnum::UTENTE->value);

    return $user->fresh();
}

it('chi ha già un\'anagrafica non ne crea una seconda dal portale', function () {
    $user = condominoDelPortale();
    $condominio = Condominio::factory()->create();
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => 'Mario Rossi'])->condomini()->attach($condominio->id);
    // Un invito accettato con un altro condominio: la seconda anagrafica lo ricollegherebbe.
    $altro = Condominio::factory()->create();
    Invito::create(['email' => $user->email, 'building_codes' => [$altro->codice_identificativo], 'expires_at' => now()->addDays(7), 'accepted_at' => now()]);

    $this->actingAs($user)
        ->post(route('user.anagrafiche.store'), ['nome' => 'Mario Rossi bis', 'indirizzo' => 'Via Roma 2'])
        ->assertForbidden();

    expect(Anagrafica::where('user_id', $user->id)->count())->toBe(1);
    expect(Anagrafica::where('user_id', $user->id)->value('nome'))->toBe('Mario Rossi');
    expect(Anagrafica::where('nome', 'Mario Rossi bis')->exists())->toBeFalse();
});

it('chi non ha un\'anagrafica la crea dal portale', function () {
    $user = condominoDelPortale();

    $this->actingAs($user)
        ->post(route('user.anagrafiche.store'), ['nome' => "Mario D'Angelo", 'indirizzo' => 'Via Roma 1'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Anagrafica::where('user_id', $user->id)->count())->toBe(1);
});
