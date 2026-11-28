<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le categorie dei documenti e quelle dell'agenda si leggono con il permesso di vedere ciò che raccolgono, non con il solo accesso al pannello.
 *
 * L'elenco delle categorie dei documenti e la loro lettura per il filtro dell'archivio chiedono «Visualizza documenti archivio»; la lettura
 * delle categorie per il filtro dell'agenda chiede «Visualizza scadenze in agenda». Con il solo pannello rispondono 403; con il permesso, e per
 * il collaboratore di serie, come prima.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    DB::table('categorie_documento')->insert(['name' => 'Verbali', 'description' => 'Verbali', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('categorie_evento')->insert(['name' => 'Manutenzioni', 'description' => 'Manutenzioni', 'created_at' => now(), 'updated_at' => now()]);
});

function csvUtente(string $ruolo, array $permessi): User
{
    $r = Role::create(['name' => $ruolo, 'guard_name' => 'web']);
    $r->givePermissionTo(array_map(fn (PermissionEnum $p) => $p->value, $permessi));
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($r);

    return $u;
}

test('con il solo accesso al pannello le tre letture delle categorie rispondono 403', function () {
    $u = csvUtente('Segreteria', [PermissionEnum::ACCESS_ADMIN_PANEL]);

    $this->actingAs($u)->get(route('admin.categorie.index'))->assertForbidden();
    $this->actingAs($u)->getJson(route('admin.categorie.documenti'))->assertForbidden();
    $this->actingAs($u)->getJson(route('admin.categorie.eventi'))->assertForbidden();
});

test('ogni lettura chiede il suo permesso: quello dei documenti non apre le categorie dell\'agenda, e il contrario', function () {
    $documenti = csvUtente('Archivio', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::VIEW_ARCHIVE_DOCUMENTS]);
    $agenda = csvUtente('Agenda', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::VIEW_EVENTS]);

    $this->actingAs($documenti)->get(route('admin.categorie.index'))->assertOk();
    $this->actingAs($documenti)->getJson(route('admin.categorie.documenti'))->assertOk()->assertJsonFragment(['name' => 'Verbali']);
    $this->actingAs($documenti)->getJson(route('admin.categorie.eventi'))->assertForbidden();

    $this->actingAs($agenda)->getJson(route('admin.categorie.eventi'))->assertOk()->assertJsonFragment(['name' => 'Manutenzioni']);
    $this->actingAs($agenda)->get(route('admin.categorie.index'))->assertForbidden();
    $this->actingAs($agenda)->getJson(route('admin.categorie.documenti'))->assertForbidden();
});

test('il collaboratore di serie le legge tutte come prima', function () {
    $c = User::factory()->create(['email_verified_at' => now()]);
    $c->assignRole(RoleEnum::COLLABORATORE->value);

    $this->actingAs($c)->get(route('admin.categorie.index'))->assertOk();
    $this->actingAs($c)->getJson(route('admin.categorie.documenti'))->assertOk();
    $this->actingAs($c)->getJson(route('admin.categorie.eventi'))->assertOk();
});
