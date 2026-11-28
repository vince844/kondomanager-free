<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il piano dei conti nasce e si modifica solo su una gestione del suo condominio: la gestione di un altro condominio, mandata con una richiesta
 * costruita, si rifiuta con l'errore sul campo e il piano non nasce (o resta com'era), così il preventivo e il riparto dell'altro condominio
 * non leggono voci che non sono loro.
 */

use App\Models\Gestionale\PianoConto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->assignRole($ruolo);
});

test('la gestione di un altro condominio in un piano nuovo: errore su «gestione_id» e nessun piano; la propria passa', function () {
    [$aurora, $esercizioAurora, $gestioneAurora] = setupContabile();
    [$belvedere, , $gestioneBelvedere] = setupContabile();
    $prima = PianoConto::count();
    $rotta = route('admin.gestionale.esercizi.piani-conti.store', ['condominio' => $aurora->id, 'esercizio' => $esercizioAurora->id]);

    $this->actingAs($this->user)->post($rotta, ['nome' => 'Ordinaria 2026', 'descrizione' => 'Il piano', 'gestione_id' => $gestioneBelvedere->id])
        ->assertSessionHasErrors(['gestione_id' => 'La gestione scelta non è di questo condominio.']);
    expect(PianoConto::count())->toBe($prima)
        ->and(PianoConto::where('gestione_id', $gestioneBelvedere->id)->where('condominio_id', $aurora->id)->exists())->toBeFalse();

    $this->actingAs($this->user)->post($rotta, ['nome' => 'Ordinaria 2026 bis', 'descrizione' => 'Il piano', 'gestione_id' => $gestioneAurora->id])
        ->assertSessionHasNoErrors();
    expect(PianoConto::where('nome', 'Ordinaria 2026 bis')->value('gestione_id'))->toBe($gestioneAurora->id);
});

test('in modifica la gestione di un altro condominio: errore su «gestione_id» e il piano resta sulla sua', function () {
    [$aurora, $esercizioAurora, $gestioneAurora, , $capitolo] = setupContabile();
    [, , $gestioneBelvedere] = setupContabile();
    $piano = PianoConto::findOrFail($capitolo->piano_conto_id);
    $rotta = route('admin.gestionale.esercizi.piani-conti.update', ['condominio' => $aurora->id, 'esercizio' => $esercizioAurora->id, 'pianoConto' => $piano->id]);

    $this->actingAs($this->user)->put($rotta, ['nome' => $piano->nome, 'descrizione' => 'Il piano', 'gestione_id' => $gestioneBelvedere->id])
        ->assertSessionHasErrors(['gestione_id' => 'La gestione scelta non è di questo condominio.']);
    expect($piano->fresh()->gestione_id)->toBe($gestioneAurora->id);

    $this->actingAs($this->user)->put($rotta, ['nome' => 'Rinominato', 'descrizione' => 'Il piano', 'gestione_id' => $gestioneAurora->id])->assertSessionHasNoErrors();
    expect($piano->fresh()->nome)->toBe('Rinominato');
});
