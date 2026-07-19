<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    
    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

test('Bug 2: modifica di un sottoconto salva correttamente il campo codice', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    
    // Capitolo padre
    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'codice'         => null,
        'nome'           => 'Ascensore',
        'tipo'           => 'spesa',
    ]);

    // Sottoconto esistente
    $sottoconto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => $capitolo->id,
        'codice'         => null,
        'nome'           => 'Manutenzione Ordinaria',
        'tipo'           => 'spesa',
    ]);

    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $sottoconto]), [
            'parent_id' => $capitolo->id,
            'codice'    => 'ASC-001',
            'nome'      => 'Manutenzione Ordinaria ASC',
            'tipo'      => 'spesa',
            'isCapitolo' => false,
            'isSottoConto' => true,
            'importo'   => '1000',
            'tabella_millesimale_id' => $tabellaId,
            'percentuale_proprietario' => 50,
            'percentuale_inquilino' => 50,
            'percentuale_usufruttuario' => 0,
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    // Verify DB state
    $sottoconto->refresh();
    expect($sottoconto->nome)->toBe('Manutenzione Ordinaria ASC');
    expect($sottoconto->codice)->toBe('ASC-001'); // Questo prima falliva perché 'codice' non era nel fillable!
});

/**
 * Regressione: un "capitolo padre" è un conto di PRIMO LIVELLO (parent_id NULL),
 * non un conto con importo 0. Prima la lista dei padri filtrava solo su importo == 0,
 * quindi un sotto-conto lasciato a zero veniva scambiato per un capitolo.
 */
test('Un sotto-conto lasciato a 0 non viene proposto come capitolo padre', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);

    // Capitolo di primo livello, vuoto: padre legittimo
    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => null,
        'importo'        => 0,
        'nome'           => 'SCALA 36',
        'tipo'           => 'spesa',
    ]);

    // Sotto-conto lasciato a 0: NON deve mai comparire tra i possibili padri
    $sottocontoAZero = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => $capitolo->id,
        'importo'        => 0,
        'nome'           => 'MANUTENZIONI ORDINARIE',
        'tipo'           => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('admin.gestionale.fetch-capitoli-conti', [
            'condominio'     => $condominio->id,
            'piano_conto_id' => $pianoConti->id,
        ]));

    $response->assertOk();
    $ids = collect($response->json())->pluck('id')->all();

    expect($ids)->toContain($capitolo->id);
    expect($ids)->not->toContain($sottocontoAZero->id); // prima del fix veniva incluso
});

test('Il conto in modifica è escluso dai capitoli padre (no figlio di sé stesso)', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);

    $capitoloA = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null,
        'importo' => 0, 'nome' => 'SCALA 36', 'tipo' => 'spesa',
    ]);
    $capitoloB = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null,
        'importo' => 0, 'nome' => 'SCALA 40', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('admin.gestionale.fetch-capitoli-conti', [
            'condominio'     => $condominio->id,
            'piano_conto_id' => $pianoConti->id,
            'conto_id'       => $capitoloA->id, // sto modificando A
        ]));

    $response->assertOk();
    $ids = collect($response->json())->pluck('id')->all();

    expect($ids)->not->toContain($capitoloA->id); // sé stesso mai proponibile
    expect($ids)->toContain($capitoloB->id);      // gli altri capitoli restano
});

test('Update: un sotto-conto non può essere scelto come capitolo padre', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null,
        'importo' => 0, 'nome' => 'SCALA 36', 'tipo' => 'spesa',
    ]);
    $sottocontoAZero = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id,
        'importo' => 0, 'nome' => 'MANUTENZIONI ORDINARIE', 'tipo' => 'spesa',
    ]);
    $altroSottoconto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id,
        'importo' => 10000, 'nome' => 'FORZA MOTRICE', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $altroSottoconto]), [
            'parent_id'    => $sottocontoAZero->id, // padre = un altro SOTTO-CONTO
            'nome'         => 'FORZA MOTRICE',
            'tipo'         => 'spesa',
            'isCapitolo'   => false,
            'isSottoConto' => true,
            'importo'      => '100',
            'tabella_millesimale_id'    => $tabellaId,
            'percentuale_proprietario'  => 100,
            'percentuale_inquilino'     => 0,
            'percentuale_usufruttuario' => 0,
        ]);

    $response->assertSessionHasErrors('parent_id');

    $altroSottoconto->refresh();
    expect($altroSottoconto->parent_id)->toBe($capitolo->id); // padre invariato
});

test('Update: un conto non può essere il padre di sé stesso', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null,
        'importo' => 0, 'nome' => 'SCALA 36', 'tipo' => 'spesa',
    ]);
    $sottoconto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id,
        'importo' => 10000, 'nome' => 'FORZA MOTRICE', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $sottoconto]), [
            'parent_id'    => $sottoconto->id, // sé stesso
            'nome'         => 'FORZA MOTRICE',
            'tipo'         => 'spesa',
            'isCapitolo'   => false,
            'isSottoConto' => true,
            'importo'      => '100',
            'tabella_millesimale_id'    => $tabellaId,
            'percentuale_proprietario'  => 100,
            'percentuale_inquilino'     => 0,
            'percentuale_usufruttuario' => 0,
        ]);

    $response->assertSessionHasErrors('parent_id');

    $sottoconto->refresh();
    expect($sottoconto->parent_id)->toBe($capitolo->id); // padre invariato
});
