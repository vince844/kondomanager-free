<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La pagina «Nuovo utente» propone fra i permessi aggiuntivi «Accesso pannello amministratore» solo all'amministratore, con la stessa
 * regola del server (`ValidaConcessioneRuoli::regolaPermessiConcedibili`): al collaboratore e a un ruolo su misura che crea utenti non lo
 * offre, perché salvarlo darebbe un rifiuto.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);
    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $segreteria = Role::create(['name' => 'segreteria', 'guard_name' => 'web', 'description' => 'Segreteria']);
    $segreteria->givePermissionTo([PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::VIEW_USERS->value, PermissionEnum::CREATE_USERS->value]);
    $this->segreteria = User::factory()->create(['email_verified_at' => now()]);
    $this->segreteria->assignRole('segreteria');
});

function pnpPermessi($test, User $chi): array
{
    $risposta = $test->actingAs($chi)->get(route('utenti.create'))->assertOk();
    $permessi = $risposta->viewData('page')['props']['permissions'];

    return collect($permessi['data'] ?? $permessi)->pluck('name')->all();
}

test('al collaboratore e al ruolo su misura «Nuovo utente» non propone il pannello, ma gli altri permessi che hanno sì', function () {
    foreach ([$this->collaboratore, $this->segreteria] as $chi) {
        $nomi = pnpPermessi($this, $chi);

        expect($nomi)->not->toContain(PermissionEnum::ACCESS_ADMIN_PANEL->value)
            ->and($nomi)->toContain(PermissionEnum::VIEW_USERS->value);
    }
});

test('all\'amministratore «Nuovo utente» propone anche il pannello', function () {
    expect(pnpPermessi($this, $this->amministratore))->toContain(PermissionEnum::ACCESS_ADMIN_PANEL->value);
});
