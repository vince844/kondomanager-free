<?php

use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Regressione: POST movimenti-rate/{scrittura}/storno prendeva {scrittura} grezzo
 * dalla rotta senza verificare che fosse davvero un incasso_rata. L'unico guard di
 * tipo nell'Action blocca solo le RETTIFICA, quindi l'id di una scrittura di
 * QUALSIASI altro tipo (es. un giroconto) nello stesso condominio veniva stornato
 * come se fosse un incasso: stato -> 'annullata' + creazione di una rettifica
 * "incasso" senza senso per quel tipo movimento.
 */
function adminStornoIncasso(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

test('lo storno rifiuta una scrittura che non è un incasso_rata (es. un giroconto)', function () {
    $user = adminStornoIncasso();
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione] = $ctx;

    $giroconto = ScritturaContabile::create([
        'condominio_id'      => $condominio->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'data_registrazione' => '2026-03-10',
        'data_competenza'    => '2026-03-10',
        'causale'            => 'Giroconto di test',
        'tipo_movimento'     => 'giroconto',
        'stato'              => 'registrata',
    ]);

    $this->actingAs($user)
        ->post(route('admin.gestionale.movimenti-rate.storno', [
            'condominio' => $condominio->id,
            'scrittura'  => $giroconto->id,
        ]))
        ->assertForbidden();

    expect($giroconto->fresh()->stato)->toBe('registrata');
    expect(ScritturaContabile::where('scrittura_padre_id', $giroconto->id)->count())->toBe(0);
});

test('lo storno funziona regolarmente per un vero incasso_rata', function () {
    $user = adminStornoIncasso();
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione] = $ctx;

    $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'crediti_condomini')
        ->firstOrFail();

    $contoPassate = ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'passate_gestioni')
        ->firstOrFail();

    $incasso = ScritturaContabile::create([
        'condominio_id'      => $condominio->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'data_registrazione' => '2026-03-10',
        'data_competenza'    => '2026-03-10',
        'causale'            => 'Incasso di test',
        'tipo_movimento'     => 'incasso_rata',
        'stato'              => 'registrata',
    ]);

    // Due righe in pareggio: la rettifica dello storno è lo specchio esatto
    // dell'originale, quindi DoubleEntryValidator la rifiuta se l'originale
    // stesso non è già in partita doppia bilanciata.
    $incasso->righe()->create([
        'conto_contabile_id' => $contoCrediti->id,
        'tipo_riga'          => 'avere',
        'importo'            => 10000,
    ]);
    $incasso->righe()->create([
        'conto_contabile_id' => $contoPassate->id,
        'tipo_riga'          => 'dare',
        'importo'            => 10000,
    ]);

    $this->actingAs($user)
        ->post(route('admin.gestionale.movimenti-rate.storno', [
            'condominio' => $condominio->id,
            'scrittura'  => $incasso->id,
        ]))
        ->assertRedirect();

    expect($incasso->fresh()->stato)->toBe('annullata');
    expect(ScritturaContabile::where('scrittura_padre_id', $incasso->id)->where('tipo_movimento', 'rettifica')->count())->toBe(1);
});
