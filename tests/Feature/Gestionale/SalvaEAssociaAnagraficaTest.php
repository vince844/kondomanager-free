<?php

/**
 * "Salva e associa le anagrafiche" (issue #10).
 *
 * Il terzo bottone della scheda "Nuovo immobile" salva l'unità e manda alla pagina di associazione
 * dell'unità appena creata; "Salva Immobile" resta com'era e manda all'elenco. Qui si verifica solo
 * la destinazione del redirect, non il rendering delle due pagine.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(
        Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web'])
    );

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->condominio = Condominio::factory()->create();
    Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
    PianoConto::factory()->create(['condominio_id' => $this->condominio->id]);

    $this->tipologiaId = DB::table('tipologie_immobili')->value('id')
        ?? DB::table('tipologie_immobili')->insertGetId([
            'nome'       => 'Appartamento',
            'categoria'  => 'A/2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
});

function datiNuovaUnita($test, array $extra = []): array
{
    return array_merge([
        'nome'          => 'Appartamento 4',
        'interno'       => '4',
        'condominio_id' => $test->condominio->id,
        'tipologia_id'  => $test->tipologiaId,
        'created_by'    => $test->user->id,
    ], $extra);
}

it('con "Salva e associa le anagrafiche" salva l\'unità e va alla pagina di associazione', function () {
    $risposta = $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            datiNuovaUnita($this, ['associa_anagrafica' => true]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

    $immobile = Immobile::where('condominio_id', $this->condominio->id)->firstOrFail();

    $risposta->assertRedirect(route('admin.gestionale.immobili.anagrafiche.create', [
        'condominio' => $this->condominio->id,
        'immobile'   => $immobile->id,
    ]));
});

it('con il solo "Salva Immobile" torna all\'elenco, come prima', function () {
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            datiNuovaUnita($this, ['associa_anagrafica' => false]))
        ->assertRedirect(route('admin.gestionale.immobili.index', ['condominio' => $this->condominio->id]));

    expect(Immobile::where('condominio_id', $this->condominio->id)->count())->toBe(1);
});
