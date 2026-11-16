<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La pagina di un fondo senza tipo (un fondo di prima che il tipo esistesse): il resto del programma lo conta come vincolato, e la pagina lo dice con le stesse
 * parole («tipo non indicato: conta come vincolato»), non «generico». Il tipo resta vuoto finché l'amministratore non lo sceglie, e per salvare va scelto: un
 * salvataggio senza tipo non lo rende libero da usare. Un fondo con il tipo e una cassa che non è un fondo restano come prima.
 */

use App\Http\Resources\Gestionale\Casse\UpdateCassaResource;
use App\Models\Gestionale\Cassa;
use App\Models\User;
use App\Services\Gestionale\LiquiditaVincolataService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

function fstCassa(int $condominioId, string $tipo, ?string $sottotipo): Cassa
{
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $condominioId, 'ruolo' => 'fst_'.uniqid(), 'codice' => '1300.'.uniqid(), 'nome' => 'Conto',
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);
    $cassa = Cassa::create(['condominio_id' => $condominioId, 'nome' => 'Fondo di riserva '.uniqid(), 'tipo' => $tipo, 'conto_contabile_id' => $conto, 'saldo_iniziale' => 0,
        'attiva' => true]);
    // Il tipo com'è nella tabella, anche vuoto: il salvataggio del modello lo riempirebbe.
    DB::table('casse')->where('id', $cassa->id)->update(['sottotipo_fondo' => $sottotipo]);

    return $cassa->fresh();
}

it('un fondo senza tipo arriva alla pagina senza tipo, con le parole del resto del programma; uno con il tipo e una banca come prima', function () {
    [$condominio] = setupContabile();
    $risorsa = fn (Cassa $c) => (new UpdateCassaResource($c))->toArray(request());

    $senza = $risorsa(fstCassa($condominio->id, 'fondo', null));
    expect($senza['sottotipo_fondo'])->toBeNull()
        ->and($senza['tipo_non_indicato'])->toBe(LiquiditaVincolataService::SOTTOTIPO_NON_INDICATO.': conta come vincolato')
        ->and($senza['tipo_non_indicato'])->not->toContain('generico');
    expect($risorsa(fstCassa($condominio->id, 'fondo', 'vincolato_lavori')))->toMatchArray(['sottotipo_fondo' => 'vincolato_lavori', 'tipo_non_indicato' => null])
        ->and($risorsa(fstCassa($condominio->id, 'fondo', 'generico')))->toMatchArray(['sottotipo_fondo' => 'generico', 'tipo_non_indicato' => null])
        ->and($risorsa(fstCassa($condominio->id, 'banca', null)))->toMatchArray(['sottotipo_fondo' => 'generico', 'tipo_non_indicato' => null]);
});

it('salvato senza scegliere il tipo, il fondo non diventa «generico»: il tipo si chiede, e resta vuoto', function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $user = User::factory()->create();
    $user->assignRole($ruolo);
    [$condominio] = setupContabile();
    $fondo = fstCassa($condominio->id, 'fondo', null);

    $this->actingAs($user)->put(route('admin.gestionale.casse.update', [$condominio, $fondo]), ['nome' => 'Fondo di riserva', 'tipo' => 'fondo', 'sottotipo_fondo' => null])
        ->assertSessionHasErrors('sottotipo_fondo');
    expect(DB::table('casse')->where('id', $fondo->id)->value('sottotipo_fondo'))->toBeNull();
});
