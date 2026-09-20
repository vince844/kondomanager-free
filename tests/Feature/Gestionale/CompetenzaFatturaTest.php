<?php

/**
 * B2 (1.11.0-beta.31), S6 — la competenza dichiarata sulla fattura arriva nelle colonne `competenza_dal/al`,
 * dal form di registrazione e da quello di modifica. Entrambi gli estremi o nessuno (il motore con uno solo
 * scenderebbe al gradino successivo in silenzio), `al ≥ dal`, `dal = al` è la delibera puntuale. La vecchia
 * chiave `dati_extra.competenza` non si scrive più, e la modifica la toglie dalle righe che la portavano.
 */

use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\FatturaPassivaService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    app()[Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = App\Models\User::factory()->create();
    $this->user->assignRole($ruolo);
});

require_once __DIR__.'/FatturaLifecycleTest.php';

function cfCorpo(array $ctx, string $numero, array $extra = []): array
{
    [, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    return array_merge([
        'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura', 'numero_documento' => $numero,
        'data_documento' => '2026-03-01', 'data_scadenza' => '2026-04-01',
        'modalita_pagamento' => 'bonifico', 'applica_ritenuta' => null, 'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'override_budget' => null],
        'righe' => [['descrizione' => 'Intervento', 'importo_imponibile' => 100, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false]],
    ], $extra);
}

it('registra la competenza come periodo nelle colonne, e non più in dati_extra', function () {
    $ctx = setupEcosistemaLifecycle();
    [$condominio] = $ctx;

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $condominio->id), cfCorpo($ctx, 'CF-1', ['competenza_dal' => '2026-01-01', 'competenza_al' => '2026-06-30']))
        ->assertSessionHasNoErrors();

    $f = FatturaPassiva::where('numero_documento', 'CF-1')->sole();
    expect($f->competenza_dal->toDateString())->toBe('2026-01-01')
        ->and($f->competenza_al->toDateString())->toBe('2026-06-30')
        ->and($f->dati_extra)->not->toHaveKey('competenza');
});

it('«spesa deliberata il» è la stessa data nei due estremi, e senza competenza le colonne restano nulle', function () {
    $ctx = setupEcosistemaLifecycle();
    [$condominio] = $ctx;

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $condominio->id), cfCorpo($ctx, 'CF-D', ['competenza_dal' => '2026-02-12', 'competenza_al' => '2026-02-12']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $condominio->id), cfCorpo($ctx, 'CF-N', ['competenza_dal' => '', 'competenza_al' => '']))
        ->assertSessionHasNoErrors();

    $d = FatturaPassiva::where('numero_documento', 'CF-D')->sole();
    $n = FatturaPassiva::where('numero_documento', 'CF-N')->sole();
    expect($d->competenza_dal->toDateString())->toBe('2026-02-12')->and($d->competenza_al->toDateString())->toBe('2026-02-12')
        ->and($n->competenza_dal)->toBeNull()->and($n->competenza_al)->toBeNull();
});

it('rifiuta la metà della competenza e la fine prima dell\'inizio', function () {
    $ctx = setupEcosistemaLifecycle();
    [$condominio] = $ctx;
    $rotta = route('admin.gestionale.fatture.store', $condominio->id);

    $this->actingAs($this->user)->postJson($rotta, cfCorpo($ctx, 'CF-M1', ['competenza_dal' => '2026-01-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('competenza_al');
    $this->actingAs($this->user)->postJson($rotta, cfCorpo($ctx, 'CF-M2', ['competenza_al' => '2026-01-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('competenza_dal');
    $this->actingAs($this->user)->postJson($rotta, cfCorpo($ctx, 'CF-M3', ['competenza_dal' => '2026-06-30', 'competenza_al' => '2026-01-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('competenza_al');

    expect(FatturaPassiva::count())->toBe(0);
});

it('la modifica scrive, cambia e toglie la competenza, e pulisce la vecchia chiave di dati_extra', function () {
    $ctx = setupEcosistemaLifecycle();
    [$condominio] = $ctx;

    $f = (new FatturaPassivaService())->registraFattura(cfCorpo($ctx, 'CF-E'), $condominio->id);
    // Una riga di prima della beta.31, col vuoto che il form scriveva a ogni salvataggio.
    $f->forceFill(['dati_extra' => array_merge($f->dati_extra ?? [], ['competenza' => ['dal' => '', 'al' => '']])])->save();
    expect($f->fresh()->competenza_dal)->toBeNull();

    $corpo = cfCorpo($ctx, 'CF-E');
    unset($corpo['tipo_documento'], $corpo['stato_approvazione']);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.fatture.update', [$condominio->id, $f->id]), array_merge($corpo, ['competenza_dal' => '2026-03-01', 'competenza_al' => '2026-03-31']))
        ->assertSessionHasNoErrors();
    $f->refresh();
    expect($f->competenza_dal->toDateString())->toBe('2026-03-01')->and($f->competenza_al->toDateString())->toBe('2026-03-31')
        ->and($f->dati_extra)->not->toHaveKey('competenza');

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.fatture.update', [$condominio->id, $f->id]), array_merge($corpo, ['competenza_dal' => '', 'competenza_al' => '']))
        ->assertSessionHasNoErrors();
    $f->refresh();
    expect($f->competenza_dal)->toBeNull()->and($f->competenza_al)->toBeNull();

    $this->actingAs($this->user)
        ->putJson(route('admin.gestionale.fatture.update', [$condominio->id, $f->id]), array_merge($corpo, ['competenza_dal' => '2026-03-31', 'competenza_al' => '2026-03-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('competenza_al');
});
