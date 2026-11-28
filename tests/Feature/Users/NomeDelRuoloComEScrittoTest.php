<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il nome di un ruolo arriva alle pagine com'è scritto, e la maiuscola iniziale è solo a video (le colonne dell'elenco,
 * la tendina della scheda dell'utente). La pagina di modifica del ruolo rimanda il nome che ha ricevuto: aperta e salvata
 * senza toccare niente, il ruolo «consigliere» resta «consigliere».
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

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->consigliere = Role::create(['name' => 'consigliere', 'guard_name' => 'web', 'description' => 'Il consiglio']);
    $this->consigliere->givePermissionTo(PermissionEnum::VIEW_USERS->value);
});

it('la pagina di modifica riceve il nome del ruolo com\'è scritto', function () {
    $this->actingAs($this->amministratore)
        ->get(route('ruoli.edit', ['ruoli' => $this->consigliere->id]))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('role.name', 'consigliere'));
});

it('un ruolo aperto e salvato senza toccare niente conserva il suo nome', function () {
    $props = $this->actingAs($this->amministratore)
        ->get(route('ruoli.edit', ['ruoli' => $this->consigliere->id]))
        ->assertOk()
        ->viewData('page')['props'];

    $this->actingAs($this->amministratore)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('ruoli.update', ['ruoli' => $this->consigliere->id]), [
            'name'        => (string) $props['role']['name'],
            'description' => $props['role']['description'],
            'permissions' => collect($props['role']['permissions'])->pluck('id')->all(),
            'accessAdmin' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->consigliere->fresh()->name)->toBe('consigliere');
});

it('l\'elenco dei ruoli e la scheda dell\'utente ricevono i nomi come sono scritti', function () {
    $this->actingAs($this->amministratore)
        ->get(route('ruoli.index'))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('roles', fn ($ruoli) => collect($ruoli)->pluck('name')->contains('consigliere')));

    $this->actingAs($this->amministratore)
        ->get(route('utenti.create'))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('roles', fn ($ruoli) => collect($ruoli)->pluck('name')->contains('consigliere')
            && collect($ruoli)->pluck('name')->contains('amministratore')));
});
