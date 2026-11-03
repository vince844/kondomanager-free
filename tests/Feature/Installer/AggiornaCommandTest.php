<?php

use App\Models\User;
use App\Services\System\SystemFinalizer;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * `kondomanager:aggiorna` (1.11.0-beta.32): i passi di `SystemFinalizer` che toccano il database,
 * per chi cambia codice senza la pagina di aggiornamento — l'entrypoint dell'immagine Docker a
 * ogni avvio. Senza questo passo un'immagine che aggiunge un permesso lo lascerebbe nel codice
 * (il guasto della beta.55, rivisto nel container dalla revisione della .32).
 */

it('su un database vuoto si ferma e rimanda a kondomanager:installa', function () {
    config([
        'database.connections.vuoto' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.default' => 'vuoto',
    ]);
    DB::setDefaultConnection('vuoto');

    try {
        $this->artisan('kondomanager:aggiorna')
            ->expectsOutputToContain('kondomanager:installa')
            ->assertFailed();
    } finally {
        DB::disconnect('vuoto');
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');
    }
});

it('su un database installato porta a database i permessi nuovi e la versione, ed è ripetibile', function () {
    Role::findOrCreate('amministratore', 'web');
    User::factory()->create();
    DB::table('settings')->where('group', 'general')->where('name', 'version')->update(['payload' => json_encode('0.0.0')]);

    $this->artisan('kondomanager:aggiorna')->assertSuccessful();

    $permessiInCodice = count(App\Enums\Permission::cases());
    expect(Permission::count())->toBe($permessiInCodice)
        ->and(json_decode(DB::table('settings')->where('group', 'general')->where('name', 'version')->value('payload')))->toBe(config('app.version'));

    $this->artisan('kondomanager:aggiorna')->assertSuccessful();
    expect(Permission::count())->toBe($permessiInCodice);
});

/*
 * Le due vie d'aggiornamento — `finalize()` e questo comando — percorrono un elenco solo,
 * `SystemFinalizer::passiDatabase()` (1.11.0-beta.33, dopo che la rilettura del flusso ha trovato
 * il comando con cinque passi rielencati a mano). Un passo nuovo entra nell'elenco, e questo test
 * lo vede: le chiavi sono fisse di proposito.
 */

it('le due vie d\'aggiornamento percorrono lo stesso elenco di passi, e l\'elenco è questo', function () {
    expect(array_keys(app(SystemFinalizer::class)->passiDatabase()))
        ->toBe(['migrazioni', 'ruoli_e_permessi', 'comuni', 'ateco', 'versione']);

    $comando = file_get_contents(app_path('Console/Commands/AggiornaCommand.php'));
    $finalizer = file_get_contents(app_path('Services/System/SystemFinalizer.php'));

    expect($comando)->toContain('passiDatabase()')
        ->and($comando)->not->toContain('runMigrationsWithRetry(')
        ->and($comando)->not->toContain('caricaElencoComuni(')
        ->and($finalizer)->toContain('foreach ($this->passiDatabase() as $passo)');
});

it('da tabelle vuote il comando carica comuni e ATECO, non solo i permessi', function () {
    Role::findOrCreate('amministratore', 'web');
    User::factory()->create();
    DB::table('comuni')->delete();
    DB::table('codici_ateco')->delete();

    $this->artisan('kondomanager:aggiorna')->assertSuccessful();

    expect(DB::table('comuni')->count())->toBe(7894)
        ->and(DB::table('codici_ateco')->count())->toBeGreaterThan(1000);
});
