<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'unità si crea, si modifica e si elimina con «Modifica condomini», come le impostazioni del condominio e le regole di recupero.
 *
 * Con il solo accesso al pannello l'elenco e la scheda dell'unità si aprono, ma «Nuovo immobile», «Modifica» ed «Elimina» rispondono 403 e
 * l'unità resta com'era. Il collaboratore di serie ha «Modifica condomini»: per lui non cambia niente.
 *
 * L'elenco delle unità manda dei soggetti di ogni unità solo ciò che la colonna «Soggetti» mostra: il nome, l'indirizzo, il ruolo, la quota
 * e le date; non il codice fiscale, che resta nella scheda dei titolari dell'unità.
 *
 * Fuori: palazzine, scale e tabelle millesimali (la stessa domanda, per il modulo dei permessi); le titolarità e i passaggi.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->soloPannello = scmUtente('Segreteria', [PermissionEnum::ACCESS_ADMIN_PANEL]);
    $this->conCondomini = scmUtente('Segreteria con condomini', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::EDIT_CONDOMINI]);
    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->tipologia = TipologiaImmobile::firstOrCreate(['nome' => 'Appartamento'], ['categoria' => 'unita_abitativa']);
    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    Esercizio::factory()->create(['condominio_id' => $this->aurora->id, 'stato' => 'aperto']);
    $this->unita = Immobile::create(['condominio_id' => $this->aurora->id, 'nome' => 'Appartamento 7', 'interno' => '7', 'descrizione' => 'Unità',
        'tipologia_id' => $this->tipologia->id, 'superficie' => 80]);
});

function scmUtente(string $ruolo, array $permessi): User
{
    $r = Role::create(['name' => $ruolo, 'guard_name' => 'web']);
    $r->givePermissionTo(array_map(fn (PermissionEnum $p) => $p->value, $permessi));
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($r);

    return $u;
}

function scmCorpo($test, array $altro = []): array
{
    return array_merge(['nome' => 'Appartamento 7', 'interno' => '7', 'condominio_id' => $test->aurora->id, 'tipologia_id' => $test->tipologia->id, 'superficie' => 80], $altro);
}

test('con il solo accesso al pannello «Nuovo immobile», «Modifica» ed «Elimina» rispondono 403 e l\'unità resta com\'era; l\'elenco e la scheda si aprono', function () {
    $u = $this->soloPannello;
    $c = ['condominio' => $this->aurora->id];
    $i = $c + ['immobile' => $this->unita->id];

    $this->actingAs($u)->get(route('admin.gestionale.immobili.index', $c))->assertOk();
    $this->actingAs($u)->get(route('admin.gestionale.immobili.show', $i))->assertOk();

    $this->actingAs($u)->get(route('admin.gestionale.immobili.create', $c))->assertForbidden();
    $this->actingAs($u)->post(route('admin.gestionale.immobili.store', $c), scmCorpo($this, ['nome' => 'Appartamento 8', 'interno' => '8']))->assertForbidden();
    $this->actingAs($u)->get(route('admin.gestionale.immobili.edit', $i))->assertForbidden();
    $this->actingAs($u)->put(route('admin.gestionale.immobili.update', $i), scmCorpo($this, ['superficie' => 95]))->assertForbidden();
    $this->actingAs($u)->delete(route('admin.gestionale.immobili.destroy', $i))->assertForbidden();

    expect(Immobile::where('condominio_id', $this->aurora->id)->count())->toBe(1)
        ->and((float) $this->unita->fresh()->superficie)->toBe(80.0);
});

test('con «Modifica condomini» l\'unità si crea, si modifica e si elimina; il collaboratore di serie come oggi', function () {
    $c = ['condominio' => $this->aurora->id];

    foreach ([$this->conCondomini, $this->collaboratore] as $n => $u) {
        $this->actingAs($u)->get(route('admin.gestionale.immobili.create', $c))->assertOk();
        $this->actingAs($u)->post(route('admin.gestionale.immobili.store', $c), scmCorpo($this, ['nome' => 'Box '.$n, 'interno' => 'B'.$n]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $nuova = Immobile::where('nome', 'Box '.$n)->sole();
        $i = $c + ['immobile' => $nuova->id];

        $this->actingAs($u)->get(route('admin.gestionale.immobili.edit', $i))->assertOk();
        $this->actingAs($u)->put(route('admin.gestionale.immobili.update', $i), scmCorpo($this, ['nome' => 'Box '.$n, 'interno' => 'B'.$n, 'superficie' => 12]))
            ->assertSessionHasNoErrors()->assertRedirect();
        expect((float) $nuova->fresh()->superficie)->toBe(12.0);

        $this->actingAs($u)->delete(route('admin.gestionale.immobili.destroy', $i))->assertRedirect();
        expect(Immobile::whereKey($nuova->id)->exists())->toBeFalse();
    }
});

test('l\'elenco delle unità manda dei soggetti il nome, il ruolo e la quota, non il codice fiscale', function () {
    $maria = Anagrafica::factory()->create(['nome' => "Maria D'Àngelo", 'codice_fiscale' => 'DNGMRA80A41H501X']);
    $maria->condomini()->attach($this->aurora->id);
    $this->unita->anagrafiche()->attach($maria->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);

    $risposta = $this->actingAs($this->soloPannello)->get(route('admin.gestionale.immobili.index', ['condominio' => $this->aurora->id]))->assertOk();
    $soggetti = collect($risposta->viewData('page')['props']['immobili'])->firstWhere('id', $this->unita->id)['anagrafiche'];

    expect($soggetti)->toHaveCount(1)
        ->and($soggetti[0]['nome'])->toBe("Maria D'Àngelo")
        ->and($soggetti[0]['pivot']['tipologia'])->toBe('proprietario')
        ->and((float) $soggetti[0]['pivot']['quota'])->toBe(100.0)
        ->and($soggetti[0])->not->toHaveKey('codice_fiscale');
    expect(json_encode($risposta->viewData('page')['props']['immobili']))->not->toContain('DNGMRA80A41H501X');
});
