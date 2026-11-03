<?php

/**
 * # «C'è un amministratore?»: sì, no, o non so
 *
 * `CreaAmministratore::esisteUnAmministratore()` risponde `false` solo quando il database dice che
 * l'amministratore non c'è: mancano le tabelle, manca il ruolo, o nessun utente lo ha. Se la lettura
 * non riesce, risale l'eccezione della lettura, e ogni chiamante decide che cosa farne:
 * `kondomanager:installa` esce con errore senza scrivere (`InstallaCommandTest`), `/up` la conta
 * come «non installata» in un punto solo e risponde come sempre, 503 con il dettaglio.
 *
 * `PreparaDatabase::pulisciCache()` toglie sempre la cache dei permessi; il resto della cache lo
 * svuota solo con `fresh: true`, la chiamata del wizard dopo `migrate:fresh`.
 */

use App\Models\User;
use App\Services\Installer\CreaAmministratore;
use App\Services\Installer\PreparaDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->lock = sys_get_temp_dir().'/km-lettura-'.uniqid().'.lock';
    config(['installer.options.lock_file' => $this->lock]);
});

afterEach(function () {
    if (config('database.default') === 'vuoto') {
        DB::disconnect('vuoto');
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');
    }
    File::delete($this->lock);
});

/**
 * La prossima interrogazione che contiene `$frammento` non riesce, una volta sola; senza frammento,
 * la prossima interrogazione qualunque.
 */
function interrogazioneCheNonRiesceUnaVolta(?string $frammento = null): void
{
    $giaFallita = false;

    DB::connection()->beforeExecuting(function (string $query) use (&$giaFallita, $frammento) {
        if (! $giaFallita && ($frammento === null || str_contains($query, $frammento))) {
            $giaFallita = true;

            throw new QueryException(DB::getDefaultConnection(), $query, [], new PDOException('SQLSTATE[HY000] [1040] Too many connections'));
        }
    });
}

function unAmministratore(): User
{
    Role::findOrCreate('amministratore', 'web');
    $utente = User::factory()->create();
    $utente->assignRole('amministratore');

    return $utente;
}

it('/up conta come non installata un\'installazione che non si riesce a leggere, e alla lettura dopo risponde pronta', function () {
    unAmministratore();
    interrogazioneCheNonRiesceUnaVolta('model_has_roles');

    $this->get('/up')
        ->assertStatus(503)
        ->assertJson(['stato' => 'non_disponibile', 'installata' => false, 'database' => 'ok', 'migrazioni_pendenti' => 0]);

    $this->get('/up')
        ->assertOk()
        ->assertJson(['stato' => 'ok', 'installata' => true, 'database' => 'ok']);
});

it('su un database senza tabelle non c\'è un amministratore', function () {
    config([
        'database.connections.vuoto' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.default' => 'vuoto',
    ]);
    DB::setDefaultConnection('vuoto');

    expect(app(CreaAmministratore::class)->esisteUnAmministratore())->toBeFalse();
});

it('senza il ruolo di amministratore, o senza un utente che lo ha, non c\'è un amministratore', function () {
    User::factory()->create();

    expect(Role::where('name', 'amministratore')->exists())->toBeFalse()
        ->and(app(CreaAmministratore::class)->esisteUnAmministratore())->toBeFalse();

    Role::findOrCreate('amministratore', 'web');

    expect(app(CreaAmministratore::class)->esisteUnAmministratore())->toBeFalse();
});

it('con un utente che ha il ruolo c\'è un amministratore', function () {
    unAmministratore();

    expect(app(CreaAmministratore::class)->esisteUnAmministratore())->toBeTrue();
});

it('se la lettura degli utenti con il ruolo non riesce, risale l\'eccezione della lettura', function () {
    unAmministratore();
    interrogazioneCheNonRiesceUnaVolta('model_has_roles');

    expect(fn () => app(CreaAmministratore::class)->esisteUnAmministratore())->toThrow(QueryException::class, 'Too many connections');
});

it('se la lettura delle tabelle non riesce, risale l\'eccezione della lettura', function () {
    unAmministratore();
    interrogazioneCheNonRiesceUnaVolta();

    expect(fn () => app(CreaAmministratore::class)->esisteUnAmministratore())->toThrow(QueryException::class, 'Too many connections');
});

it('pulisciCache toglie la cache dei permessi e lascia il resto della cache', function () {
    $permessi = app(PermissionRegistrar::class);
    $permessi->getCacheStore()->put($permessi->cacheKey, 'i permessi letti prima', 600);
    Cache::put('valore-dell-installazione', 'resta', 600);

    app(PreparaDatabase::class)->pulisciCache();

    expect($permessi->getCacheStore()->get($permessi->cacheKey))->toBeNull()
        ->and(Cache::get('valore-dell-installazione'))->toBe('resta');
});

it('pulisciCache con fresh svuota tutta la cache, permessi compresi', function () {
    $permessi = app(PermissionRegistrar::class);
    $permessi->getCacheStore()->put($permessi->cacheKey, 'i permessi letti prima', 600);
    Cache::put('valore-di-prima', 'via', 600);

    app(PreparaDatabase::class)->pulisciCache(fresh: true);

    expect($permessi->getCacheStore()->get($permessi->cacheKey))->toBeNull()
        ->and(Cache::get('valore-di-prima'))->toBeNull();
});
