<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il saldo iniziale si scrive a nome di una persona del condominio, o di chi ha (o ha avuto) una riga sull'unità del saldo, come il venditore
 * uscito; la persona di un altro condominio, mandata con una richiesta costruita, si rifiuta con l'errore sul campo e non nasce nessuna riga, così
 * la pagina dei saldi e il piano rate del condominio non mostrano un nome che non è suo. In modifica la gestione resta quella della riga: per
 * spostarla si elimina e si registra di nuovo.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Models\User;
use App\Http\Requests\Gestionale\Saldi\CreateSaldoRequest;
use App\Http\Requests\Gestionale\Saldi\UpdateSaldoRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->assignRole($ruolo);
    $this->travelTo('2026-01-02 10:00:00');
    $this->s = lcdScenario(['A' => ['ugo', 600], 'B' => ['bice', 400]], 12, 'prima_rata');
});

function spcRegistra($test, Anagrafica $persona, int $importo = 15000)
{
    $s = $test->s;

    return $test->actingAs($test->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $persona->id, 'immobile_id' => $s['immobili']['A']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo,
    ]);
}

test('la persona di un altro condominio: errore su «anagrafica_id» e nessuna riga', function () {
    $belvedere = Condominio::factory()->create(['nome' => 'Condominio Belvedere']);
    $franco = Anagrafica::factory()->create(['nome' => 'Franco Neri']);
    $franco->condomini()->attach($belvedere->id);

    spcRegistra($this, $franco)->assertSessionHasErrors(['anagrafica_id' => CreateSaldoRequest::FRASE_PERSONA_ALTRUI]);
    expect(Saldo::where('anagrafica_id', $franco->id)->exists())->toBeFalse();
});

test('la persona del condominio, e chi ha avuto una riga sull\'unità anche se oggi non è nel condominio: passano', function () {
    $s = $this->s;
    spcRegistra($this, $s['persone']['ugo'])->assertSessionHasNoErrors();

    // Bice è del condominio, anche se non ha righe sull'unità A.
    spcRegistra($this, $s['persone']['bice'], 2000)->assertSessionHasNoErrors();

    // Il venditore uscito: una riga chiusa sull'unità, e fuori dalle persone del condominio.
    $mario = Anagrafica::factory()->create(['nome' => 'Mario Rossi']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $mario->id, 'immobile_id' => $s['immobili']['A']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => false, 'data_inizio' => '2015-01-01', 'data_fine' => '2025-12-31']);
    spcRegistra($this, $mario, 3000)->assertSessionHasNoErrors();

    expect(Saldo::whereIn('anagrafica_id', [$s['persone']['ugo']->id, $s['persone']['bice']->id, $mario->id])->count())->toBe(3);
});

test('in modifica la gestione resta quella della riga: un\'altra gestione del condominio dà errore e la riga resta com\'era', function () {
    $s = $this->s;
    spcRegistra($this, $s['persone']['ugo'])->assertSessionHasNoErrors();
    $saldo = Saldo::where('anagrafica_id', $s['persone']['ugo']->id)->sole();
    $altra = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);

    $this->actingAs($this->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $saldo]), ['saldo_iniziale' => 20000, 'gestione_id' => $altra->id])
        ->assertSessionHasErrors(['gestione_id' => UpdateSaldoRequest::FRASE_GESTIONE_DELLA_RIGA]);
    $saldo->refresh();
    expect([(int) $saldo->gestione_id, (int) $saldo->saldo_iniziale])->toBe([(int) $s['g']->id, 15000]);

    $this->actingAs($this->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $saldo]), ['saldo_iniziale' => 20000, 'gestione_id' => $s['g']->id])
        ->assertSessionHasNoErrors();
    expect((int) $saldo->fresh()->saldo_iniziale)->toBe(20000);
});
