<?php

use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Verifica diretta del backfill della migrazione
 * 2026_07_22_140000_add_is_capitolo_to_conti_table — la logica che decide
 * is_capitolo per i conti che esistevano PRIMA di questa beta, su dati
 * costruiti a mano per rappresentare un piano dei conti reale già in uso.
 *
 * RefreshDatabase esegue già tutte le migrazioni (compresa questa) prima di
 * ogni test, su un database vuoto — qui creiamo le fixture DOPO, poi
 * rieseguiamo up() sulla stessa migrazione (che parte sempre con una pulizia
 * di eventuali colonne parziali) per osservare il backfill sui dati reali,
 * esattamente come accadrebbe durante un aggiornamento in produzione.
 */
function rieseguiBackfillIsCapitolo(): void
{
    $path = base_path('database/migrations/2026_07_22_140000_add_is_capitolo_to_conti_table.php');
    $migration = require $path;
    $migration->up();
}

test('backfill: capitolo vuoto (nessuna tabella, nessun sottoconto) resta true — nessun cambio rispetto a oggi', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);

    $capitoloVuoto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'importo' => 0,
    ]);

    rieseguiBackfillIsCapitolo();

    expect($capitoloVuoto->fresh()->is_capitolo)->toBeTrue();
});

test('backfill: voce con tabella millesimale reale è SEMPRE false, anche a importo zero — il fix del bug', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $tabellaId = DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $voceConTabella = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'importo' => 0,
    ]);
    DB::table('conto_tabella_millesimale')->insert([
        'conto_id' => $voceConTabella->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    rieseguiBackfillIsCapitolo();

    expect($voceConTabella->fresh()->is_capitolo)->toBeFalse();
});

test('backfill: voce con importo diverso da zero è false, anche senza tabella — replica il criterio odierno', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);

    $voceConImporto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'importo' => 50000,
    ]);

    rieseguiBackfillIsCapitolo();

    expect($voceConImporto->fresh()->is_capitolo)->toBeFalse();
});

test('backfill: capitolo con sottoconti è sempre true, anche se (inconsistenza pregressa) ha pure una tabella propria', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $tabellaId = DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $capitoloConFiglio = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'importo' => 0,
    ]);
    Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitoloConFiglio->id, 'importo' => 10000,
    ]);
    // Inconsistenza pregressa: il "capitolo" ha anche una tabella propria.
    DB::table('conto_tabella_millesimale')->insert([
        'conto_id' => $capitoloConFiglio->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    rieseguiBackfillIsCapitolo();

    expect($capitoloConFiglio->fresh()->is_capitolo)->toBeTrue();
});

test('backfill: un sottoconto (parent_id valorizzato) è sempre false, mai capitolo', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'importo' => 0,
    ]);
    $sottoconto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id, 'importo' => 0,
    ]);

    rieseguiBackfillIsCapitolo();

    expect($sottoconto->fresh()->is_capitolo)->toBeFalse();
});

test('backfill: la guardia anti-cancellazione protegge davvero un conto backfillato — scenario end-to-end', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->givePermissionTo('Accesso pannello amministratore'));

    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId = DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    // Simula un conto REALE che esisteva già prima di questa beta: voce a zero,
    // con tabella millesimale reale — esattamente lo scenario del bug originale.
    $voceLegacy = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'importo' => 0,
        'nome' => 'Manutenzione Ascensore', 'tipo' => 'spesa',
    ]);
    $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $voceLegacy->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $contoTabellaId, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Il backfill gira SOLO ora, come accadrebbe durante l'aggiornamento a questa beta.
    rieseguiBackfillIsCapitolo();
    expect($voceLegacy->fresh()->is_capitolo)->toBeFalse();

    // La modale CORRETTA rilegge is_capitolo=false e lo rimanda invariato al resave.
    $response = $this->actingAs($user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $voceLegacy]), [
            'nome' => 'Manutenzione Ascensore', 'tipo' => 'spesa',
            'isCapitolo' => false, 'isSottoConto' => false, 'importo' => '0',
            'tabella_millesimale_id' => $tabellaId,
            'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]);

    $response->assertSessionHasNoErrors();
    expect(DB::table('conto_tabella_millesimale')->where('conto_id', $voceLegacy->id)->count())->toBe(1);
});
