<?php

/**
 * beta.61 — Le rotte che **cancellano** erano scoperte come quella che legge.
 *
 * La guardia sulla pagina delle quote (`QuoteDiUnAltroCondominioTest`) chiude il percorso che
 * riscrive i millesimi di un altro condominio. Accanto ce ne sono due che li cancellano, e per la
 * stessa ragione — il binding implicito risolve `{tabella}` e `{immobile}` per id, senza legarli a
 * `{condominio}`:
 *
 * - cancellare una **tabella** porta via in cascata tutte le sue quote (`ON DELETE CASCADE` su
 *   `quote_tabella.tabella_id`, verificato sul database vivo);
 * - cancellare un'**unità** porta via le sue quote in ogni tabella del suo condominio.
 *
 * Il difetto peggiore non è la cancellazione: è che finiva con un **messaggio verde di successo**
 * sulla schermata dell'altro condominio. Un'operazione andata a buon fine, sui dati sbagliati.
 */

use App\Models\Condominio;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function utenteCheCancella(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $utente = \App\Models\User::factory()->create();
    $utente->givePermissionTo('Accesso pannello amministratore');

    return $utente;
}

function condominioPerCancellazione(): Condominio
{
    $condominio = Condominio::factory()->create();

    DB::table('esercizi')->insert([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio 2026', 'stato' => 'aperto',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Gestione Ordinaria', 'tipo' => 'ordinaria',
        'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('piani_conti')->insert([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestioneId,
        'nome' => 'Piano dei conti 2026', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $condominio;
}

function tabellaConUnaQuota(Condominio $condominio): array
{
    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Proprietà generale',
        'tipo' => 'standard', 'quota' => 'millesimi', 'numero_decimali' => 2,
        'attiva' => true, 'data_inizio' => now(),
    ]);

    $immobileId = DB::table('immobili')->insertGetId([
        'condominio_id' => $condominio->id, 'codice_immobile' => 'U'.uniqid(),
        'nome' => 'Interno 1', 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $immobileId, 'valore' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$tabella, $immobileId];
}

test('la tabella di un altro condominio non si cancella', function () {
    $mio = condominioPerCancellazione();
    $altrui = condominioPerCancellazione();
    [$tabella] = tabellaConUnaQuota($altrui);

    $this->actingAs(utenteCheCancella())
        ->delete(route('admin.gestionale.tabelle.destroy', [$mio->id, $tabella->id]))
        ->assertNotFound();

    expect(DB::table('tabelle')->where('id', $tabella->id)->count())->toBe(1)
        ->and(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(1);
});

test("l'unità di un altro condominio non si cancella", function () {
    $mio = condominioPerCancellazione();
    $altrui = condominioPerCancellazione();
    [$tabella, $immobileId] = tabellaConUnaQuota($altrui);

    $this->actingAs(utenteCheCancella())
        ->delete(route('admin.gestionale.immobili.destroy', [$mio->id, $immobileId]))
        ->assertNotFound();

    expect(DB::table('immobili')->where('id', $immobileId)->count())->toBe(1)
        ->and(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(1);
});

test('nel proprio condominio si cancella come sempre', function () {
    // Il ramo innocente: una guardia che blocca l'uso normale è un guasto, non una difesa.
    $mio = condominioPerCancellazione();
    [$tabella] = tabellaConUnaQuota($mio);

    $this->actingAs(utenteCheCancella())
        ->delete(route('admin.gestionale.tabelle.destroy', [$mio->id, $tabella->id]))
        ->assertRedirect();

    expect(DB::table('tabelle')->where('id', $tabella->id)->count())->toBe(0);
});
