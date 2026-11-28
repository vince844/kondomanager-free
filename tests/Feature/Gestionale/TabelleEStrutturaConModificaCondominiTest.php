<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Palazzine, scale, tabelle millesimali e millesimi si creano, si modificano e si eliminano con «Modifica condomini», come l'unità (Coda 714).
 *
 * Con il solo accesso al pannello gli elenchi e la pagina dei millesimi si aprono, ma «Nuova», «Modifica», «Elimina» e «Salva» dei millesimi
 * rispondono 403 e niente cambia (i millesimi di un'unità restano 42,500, non diventano 52,500). Con «Modifica condomini», e per il collaboratore
 * di serie, tutto come prima.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Condominio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->soloPannello = tsmUtente('Segreteria', [PermissionEnum::ACCESS_ADMIN_PANEL]);
    $this->conCondomini = tsmUtente('Segreteria con condomini', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::EDIT_CONDOMINI]);
    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    DB::table('esercizi')->insert(['condominio_id' => $this->aurora->id, 'nome' => 'Esercizio 2026', 'stato' => 'aperto',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
    $gestione = DB::table('gestioni')->insertGetId(['condominio_id' => $this->aurora->id, 'nome' => 'Gestione Ordinaria', 'tipo' => 'ordinaria',
        'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('piani_conti')->insert(['condominio_id' => $this->aurora->id, 'gestione_id' => $gestione, 'nome' => 'Piano dei conti 2026',
        'created_at' => now(), 'updated_at' => now()]);

    $this->palazzina = Palazzina::create(['condominio_id' => $this->aurora->id, 'name' => 'Palazzina A', 'description' => 'Lato strada']);
    $this->scala = Scala::create(['condominio_id' => $this->aurora->id, 'palazzina_id' => $this->palazzina->id, 'name' => 'Scala A', 'description' => 'Ingresso nord']);
    $this->tabella = Tabella::create(['condominio_id' => $this->aurora->id, 'nome' => 'Proprietà generale', 'tipo' => 'standard', 'quota' => 'millesimi',
        'numero_decimali' => 3, 'attiva' => true, 'data_inizio' => now()]);
    $this->unita = DB::table('immobili')->insertGetId(['condominio_id' => $this->aurora->id, 'codice_immobile' => 'U7', 'nome' => 'Appartamento 7',
        'attivo' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->quota = DB::table('quote_tabella')->insertGetId(['tabella_id' => $this->tabella->id, 'immobile_id' => $this->unita, 'valore' => 42.5,
        'created_at' => now(), 'updated_at' => now()]);
});

function tsmUtente(string $ruolo, array $permessi): User
{
    $r = Role::create(['name' => $ruolo, 'guard_name' => 'web']);
    $r->givePermissionTo(array_map(fn (PermissionEnum $p) => $p->value, $permessi));
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($r);

    return $u;
}

/** Le rotte di scrittura di una delle tre schede, con un corpo valido. */
function tsmGesti($test, string $cosa, string $nome): array
{
    $c = ['condominio' => $test->aurora->id];

    return match ($cosa) {
        'palazzine' => [
            'create' => ['get', route('admin.gestionale.palazzine.create', $c), []],
            'store' => ['post', route('admin.gestionale.palazzine.store', $c), ['name' => $nome, 'description' => 'Nuova']],
            'edit' => ['get', route('admin.gestionale.palazzine.edit', $c + ['palazzina' => $test->palazzina->id]), []],
            'update' => ['put', route('admin.gestionale.palazzine.update', $c + ['palazzina' => $test->palazzina->id]), ['name' => 'Palazzina A', 'description' => 'Cambiata']],
        ],
        'scale' => [
            'create' => ['get', route('admin.gestionale.scale.create', $c), []],
            'store' => ['post', route('admin.gestionale.scale.store', $c), ['name' => $nome, 'description' => 'Nuova', 'palazzina_id' => $test->palazzina->id]],
            'edit' => ['get', route('admin.gestionale.scale.edit', $c + ['scala' => $test->scala->id]), []],
            'update' => ['put', route('admin.gestionale.scale.update', $c + ['scala' => $test->scala->id]), ['name' => 'Scala A', 'description' => 'Cambiata', 'palazzina_id' => $test->palazzina->id]],
        ],
        'tabelle' => [
            'create' => ['get', route('admin.gestionale.tabelle.create', $c), []],
            'store' => ['post', route('admin.gestionale.tabelle.store', $c), ['nome' => $nome, 'tipologia' => 'standard', 'quota' => 'millesimi', 'numero_decimali' => 3]],
            'edit' => ['get', route('admin.gestionale.tabelle.edit', $c + ['tabella' => $test->tabella->id]), []],
            'update' => ['put', route('admin.gestionale.tabelle.update', $c + ['tabella' => $test->tabella->id]), ['nome' => 'Proprietà generale', 'tipo' => 'standard', 'quota' => 'millesimi', 'numero_decimali' => 3, 'descrizione' => 'Cambiata']],
        ],
    };
}

function tsmMillesimi($test, User $u, string $valore)
{
    return $test->actingAs($u)->put(route('admin.gestionale.tabelle.quote.update', ['condominio' => $test->aurora->id, 'tabella' => $test->tabella->id]), [
        'quote' => [['id' => $test->quota, 'immobile' => ['id' => $test->unita], 'valore' => $valore]],
    ]);
}

test('con il solo accesso al pannello palazzine, scale e tabelle non si creano, non si modificano e non si eliminano; gli elenchi si aprono', function () {
    $u = $this->soloPannello;
    $c = ['condominio' => $this->aurora->id];

    foreach (['palazzine', 'scale', 'tabelle'] as $cosa) {
        $this->actingAs($u)->get(route("admin.gestionale.{$cosa}.index", $c))->assertOk();
        foreach (tsmGesti($this, $cosa, 'Nuova '.$cosa) as $gesto => [$metodo, $url, $corpo]) {
            $this->actingAs($u)->{$metodo}($url, $corpo)->assertForbidden();
        }
    }
    $this->actingAs($u)->delete(route('admin.gestionale.scale.destroy', $c + ['scala' => $this->scala->id]))->assertForbidden();
    $this->actingAs($u)->delete(route('admin.gestionale.palazzine.destroy', $c + ['palazzina' => $this->palazzina->id]))->assertForbidden();
    $this->actingAs($u)->delete(route('admin.gestionale.tabelle.destroy', $c + ['tabella' => $this->tabella->id]))->assertForbidden();

    expect(Palazzina::where('condominio_id', $this->aurora->id)->pluck('description')->all())->toBe(['Lato strada'])
        ->and(Scala::where('condominio_id', $this->aurora->id)->pluck('description')->all())->toBe(['Ingresso nord'])
        ->and(Tabella::where('condominio_id', $this->aurora->id)->count())->toBe(1)
        ->and($this->tabella->fresh()->descrizione)->toBeNull();
});

test('con il solo accesso al pannello la pagina dei millesimi si apre ma il salvataggio risponde 403 e la quota resta 42,500', function () {
    $this->actingAs($this->soloPannello)->get(route('admin.gestionale.tabelle.quote.index', ['condominio' => $this->aurora->id, 'tabella' => $this->tabella->id]))->assertOk();

    tsmMillesimi($this, $this->soloPannello, '52.5')->assertForbidden();

    expect((float) DB::table('quote_tabella')->where('id', $this->quota)->value('valore'))->toBe(42.5);
});

test('con «Modifica condomini», e per il collaboratore di serie, palazzine, scale, tabelle e millesimi si cambiano come prima', function () {
    $c = ['condominio' => $this->aurora->id];

    foreach ([$this->conCondomini, $this->collaboratore] as $n => $u) {
        foreach (['palazzine', 'scale', 'tabelle'] as $cosa) {
            foreach (tsmGesti($this, $cosa, "Nuova {$cosa} {$n}") as $gesto => [$metodo, $url, $corpo]) {
                $r = $this->actingAs($u)->{$metodo}($url, $corpo);
                $metodo === 'get' ? $r->assertOk() : $r->assertSessionHasNoErrors()->assertRedirect();
            }
        }
        $valore = $n === 0 ? '52.5' : '60';
        tsmMillesimi($this, $u, $valore)->assertSessionHasNoErrors()->assertRedirect();
        expect((float) DB::table('quote_tabella')->where('id', $this->quota)->value('valore'))->toBe((float) $valore);
    }

    expect(Palazzina::where('name', 'Nuova palazzine 0')->exists())->toBeTrue()
        ->and(Scala::where('name', 'Nuova scale 1')->exists())->toBeTrue()
        ->and(Tabella::where('nome', 'Nuova tabelle 1')->exists())->toBeTrue()
        ->and($this->palazzina->fresh()->description)->toBe('Cambiata')
        ->and($this->tabella->fresh()->descrizione)->toBe('Cambiata');

    // L'eliminazione: le righe nuove, che niente usa.
    foreach ([['scale', 'scala', Scala::where('name', 'Nuova scale 0')->value('id')],
        ['tabelle', 'tabella', Tabella::where('nome', 'Nuova tabelle 0')->value('id')],
        ['palazzine', 'palazzina', Palazzina::where('name', 'Nuova palazzine 1')->value('id')]] as [$cosa, $param, $id]) {
        $this->actingAs($this->conCondomini)->delete(route("admin.gestionale.{$cosa}.destroy", $c + [$param => $id]))->assertSessionHasNoErrors()->assertRedirect();
    }
    expect(Scala::whereKey(Scala::where('name', 'Nuova scale 0')->value('id'))->exists())->toBeFalse()
        ->and(Tabella::where('nome', 'Nuova tabelle 0')->exists())->toBeFalse()
        ->and(Palazzina::where('name', 'Nuova palazzine 1')->exists())->toBeFalse();
});
