<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le categorie dei documenti dell'archivio si creano con «Crea documenti archivio», si rinominano con «Modifica documenti archivio» e si
 * eliminano con «Elimina documenti archvio» (il nome del permesso com'è scritto nel programma), come i documenti che raccolgono; il «+» della
 * finestra di caricamento è la stessa creazione. Con il solo accesso al pannello rispondono 403 e la categoria resta com'era.
 * L'elenco delle categorie resta aperto a chi entra nel pannello.
 */

use App\Enums\Permission as PermissionEnum;
use App\Models\CategoriaDocumento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->categoria = CategoriaDocumento::create(['name' => 'Verbali di assemblea', 'description' => 'I verbali']);
});

function cdpUtente(string $ruolo, array $permessi): User
{
    $r = Role::create(['name' => $ruolo, 'guard_name' => 'web']);
    $r->givePermissionTo(array_map(fn (PermissionEnum $p) => $p->value, $permessi));
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($r);

    return $u;
}

test('con il solo accesso al pannello creare, rinominare ed eliminare una categoria rispondono 403 e la categoria resta com\'era', function () {
    $u = cdpUtente('Segreteria', [PermissionEnum::ACCESS_ADMIN_PANEL]);
    $prima = CategoriaDocumento::count();

    // Anche l'elenco chiede «Visualizza documenti archivio»: con il solo pannello è 403 (CategorieSoloAChiVedeTest).
    $this->actingAs($u)->get(route('admin.categorie.index'))->assertForbidden();
    $this->actingAs($u)->post(route('admin.categorie.store'), ['name' => 'Varie', 'description' => 'Altro'])->assertForbidden();
    $this->actingAs($u)->postJson(route('admin.categorie.store'), ['name' => 'Varie', 'description' => 'Altro'])->assertForbidden();
    $this->actingAs($u)->put(route('admin.categorie.update', ['categoria' => $this->categoria->id]), ['name' => 'Varie', 'description' => 'Altro'])->assertForbidden();
    $this->actingAs($u)->delete(route('admin.categorie.destroy', ['categoria' => $this->categoria->id]))->assertForbidden();

    expect(CategoriaDocumento::count())->toBe($prima)
        ->and($this->categoria->fresh()->name)->toBe('Verbali di assemblea');
});

test('con i permessi dei documenti dell\'archivio la categoria si crea, si rinomina e si elimina', function () {
    $crea = cdpUtente('Archivio, crea', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::CREATE_ARCHIVE_DOCUMENTS]);
    $modifica = cdpUtente('Archivio, modifica', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::EDIT_ARCHIVE_DOCUMENTS]);
    $elimina = cdpUtente('Archivio, elimina', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::DELETE_ARCHIVE_DOCUMENTS]);

    $this->actingAs($crea)->postJson(route('admin.categorie.store'), ['name' => 'Varie', 'description' => 'Altro'])->assertOk();
    expect(CategoriaDocumento::where('name', 'Varie')->exists())->toBeTrue();
    // Chi crea non rinomina e non elimina: ognuno il suo permesso.
    $this->actingAs($crea)->put(route('admin.categorie.update', ['categoria' => $this->categoria->id]), ['name' => 'Altro', 'description' => 'Altro'])->assertForbidden();

    $this->actingAs($modifica)->put(route('admin.categorie.update', ['categoria' => $this->categoria->id]), ['name' => 'Verbali', 'description' => 'I verbali'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($this->categoria->fresh()->name)->toBe('Verbali');

    $this->actingAs($elimina)->delete(route('admin.categorie.destroy', ['categoria' => $this->categoria->id]))->assertRedirect();
    expect(CategoriaDocumento::whereKey($this->categoria->id)->exists())->toBeFalse();
});
