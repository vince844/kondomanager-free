<?php

use App\Models\User;
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
