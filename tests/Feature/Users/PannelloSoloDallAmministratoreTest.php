<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Accesso pannello amministratore» come permesso aggiuntivo lo concede solo l'amministratore.
 *
 * Il permesso apre il gestionale di tutti i condomini. Chi non è amministratore (il collaboratore, un ruolo su misura con
 * «Crea utenti» o «Modifica utenti») può dare a un condòmino i permessi che ha, ma non questo: in creazione e in modifica,
 * con l'id o con il nome, anche dentro un elenco. Un account che lo ha già lo conserva quando la sua scheda si salva: la
 * regola guarda ciò che si concede, non ciò che resta.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    // Un ruolo su misura che crea e modifica utenti ed entra nel pannello.
    $segreteria = Role::create(['name' => 'segreteria', 'guard_name' => 'web', 'description' => 'Segreteria']);
    $segreteria->givePermissionTo([
        PermissionEnum::ACCESS_ADMIN_PANEL->value,
        PermissionEnum::VIEW_USERS->value,
        PermissionEnum::CREATE_USERS->value,
        PermissionEnum::EDIT_USERS->value,
    ]);
    $this->segreteria = User::factory()->create(['email_verified_at' => now()]);
    $this->segreteria->assignRole('segreteria');

    $this->lucia = User::factory()->create(['name' => 'Lucia Ferri', 'email_verified_at' => now()]);
    $this->lucia->assignRole(RoleEnum::UTENTE->value);

    $this->pannello = Permission::where('name', PermissionEnum::ACCESS_ADMIN_PANEL->value)->firstOrFail();
    $this->utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
});

function schedaDiLucia(User $lucia, array $permessi): array
{
    return [
        'name'        => $lucia->name,
        'email'       => $lucia->email,
        'roles'       => $lucia->roles->first()?->id,
        'permissions' => $permessi,
        'anagrafica'  => null,
    ];
}

dataset('chi non è amministratore', ['il collaboratore' => 'collaboratore', 'un ruolo su misura' => 'segreteria']);
dataset('le forme del permesso', [
    'l\'id'                 => fn () => [Permission::where('name', PermissionEnum::ACCESS_ADMIN_PANEL->value)->value('id')],
    'il nome'               => fn () => [PermissionEnum::ACCESS_ADMIN_PANEL->value],
    'dentro un elenco'      => fn () => [[Permission::where('name', PermissionEnum::ACCESS_ADMIN_PANEL->value)->value('id')]],
]);

it('chi non è amministratore non dà il pannello a un condòmino dalla sua scheda', function (string $chi, array $permessi) {
    $attore = $this->{$chi};

    $this->actingAs($attore)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('utenti.edit', ['utenti' => $this->lucia->id]))
        ->put(route('utenti.update', ['utenti' => $this->lucia->id]), schedaDiLucia($this->lucia, $permessi))
        ->assertRedirect(route('utenti.edit', ['utenti' => $this->lucia->id]))
        ->assertSessionHasErrors(['permissions' => __('validation.custom.permissions.admin_panel_only_admin')]);

    expect($this->lucia->fresh()->hasPermissionTo(PermissionEnum::ACCESS_ADMIN_PANEL->value))->toBeFalse();
    // La catena: senza il permesso Lucia resta fuori dal pannello.
    $this->actingAs($this->lucia->fresh())->get('/admin/dashboard')->assertForbidden();
})->with('chi non è amministratore')->with('le forme del permesso');

it('chi non è amministratore non crea un utente con il pannello', function (string $chi, array $permessi) {
    $this->actingAs($this->{$chi})
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('utenti.create'))
        ->post(route('utenti.store'), [
            'name'        => 'Lucia Bianchi',
            'email'       => 'lucia.bianchi@example.com',
            'roles'       => $this->utente->id,
            'permissions' => $permessi,
            'anagrafica'  => null,
        ])
        ->assertRedirect(route('utenti.create'))
        ->assertSessionHasErrors(['permissions' => __('validation.custom.permissions.admin_panel_only_admin')]);

    expect(User::where('email', 'lucia.bianchi@example.com')->exists())->toBeFalse();
})->with('chi non è amministratore')->with('le forme del permesso');

it('l\'amministratore dà il pannello a un condòmino', function () {
    $this->actingAs($this->amministratore)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('utenti.update', ['utenti' => $this->lucia->id]), schedaDiLucia($this->lucia, [$this->pannello->id]))
        ->assertRedirect(route('utenti.index'))
        ->assertSessionHasNoErrors();

    expect($this->lucia->fresh()->hasDirectPermission(PermissionEnum::ACCESS_ADMIN_PANEL->value))->toBeTrue();
});

it('l\'amministratore crea un utente con il pannello', function () {
    $this->actingAs($this->amministratore)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('utenti.store'), [
            'name'        => 'Lucia Bianchi',
            'email'       => 'lucia.bianchi@example.com',
            'roles'       => $this->utente->id,
            'permissions' => [$this->pannello->id],
            'anagrafica'  => null,
        ])
        ->assertRedirect(route('utenti.index'))
        ->assertSessionHasNoErrors();

    expect(User::where('email', 'lucia.bianchi@example.com')->firstOrFail()->hasDirectPermission(PermissionEnum::ACCESS_ADMIN_PANEL->value))->toBeTrue();
});

it('un account che ha già il pannello lo conserva quando chi non è amministratore salva la sua scheda', function (string $chi) {
    $this->lucia->givePermissionTo(PermissionEnum::ACCESS_ADMIN_PANEL->value);

    $this->actingAs($this->{$chi})
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('utenti.update', ['utenti' => $this->lucia->id]), schedaDiLucia($this->lucia, [$this->pannello->id]) + ['name' => 'Lucia Ferri Rossi'])
        ->assertSessionHasNoErrors();

    $lucia = $this->lucia->fresh();
    expect($lucia->hasDirectPermission(PermissionEnum::ACCESS_ADMIN_PANEL->value))->toBeTrue();
})->with('chi non è amministratore');

it('chi non è amministratore dà a un condòmino gli altri permessi che ha', function () {
    $permesso = Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('utenti.update', ['utenti' => $this->lucia->id]), schedaDiLucia($this->lucia, [$permesso->id]))
        ->assertRedirect(route('utenti.index'))
        ->assertSessionHasNoErrors();

    expect($this->lucia->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeTrue();
});
