<?php

/**
 * # `/up` risponde 200 solo a installazione fatta (1.11.0-beta.32)
 *
 * La rotta di salute di Laravel rispondeva 200 appena l'applicazione si avviava, database o no.
 * Per un container è una bugia utile a nessuno: chi lo orchestra direbbe «pronta» al cliente su
 * un'istanza vuota. Ora risponde `SaluteController`: 200 se installazione chiusa, database
 * raggiungibile e nessuna migrazione da applicare; 503 con il dettaglio altrimenti.
 *
 * ## Cosa presidiano questi test
 *
 * 1. Database migrato ma senza installazione (né lock né amministratore) → 503, e il motivo.
 * 2. Con il lock → 200. Con un amministratore e senza lock (installazione dai sorgenti) → 200.
 * 3. Le migrazioni applicate che non hanno più un file si contano ma non rendono malata
 *    l'installazione: sul database di sviluppo ce ne sono sei, e non è un guasto.
 * 4. La rotta sta fuori dal gruppo `web`: niente sessione aperta da un controllo ogni 5 secondi.
 * 5. Database che non risponde → 503 con `database: errore`, senza eccezioni.
 * 6. Il dettaglio lo vede chi chiama da loopback o da rete privata (HEALTHCHECK, orchestratore);
 *    da internet arriva solo lo stato: una rotta senza credenziali non racconta se il wizard è
 *    aperto o quante migrazioni mancano.
 */

use App\Models\User;
use App\Services\System\StatoDiSalute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->lock = sys_get_temp_dir().'/km-salute-'.uniqid().'.lock';
    config(['installer.options.lock_file' => $this->lock]);
});

afterEach(function () {
    File::delete($this->lock);
});

it('senza installazione risponde 503 e dice perché', function () {
    $this->get('/up')
        ->assertStatus(503)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'stato' => 'non_disponibile',
            'installata' => false,
            'database' => 'ok',
            'migrazioni_pendenti' => 0,
        ]);
});

it('con il lock risponde 200', function () {
    file_put_contents($this->lock, now()->toDateTimeString());

    $this->get('/up')->assertOk()->assertJson(['stato' => 'ok', 'installata' => true]);
});

it('con un amministratore e senza lock risponde 200: è un\'installazione dai sorgenti', function () {
    Role::findOrCreate('amministratore', 'web');
    User::factory()->create()->assignRole('amministratore');

    $this->get('/up')->assertOk()->assertJson(['stato' => 'ok', 'installata' => true]);
});

it('un utente senza il ruolo di amministratore non conta come installazione', function () {
    User::factory()->create();

    $this->get('/up')->assertStatus(503);
});

it('le migrazioni applicate senza più un file si contano ma non rendono malata l\'installazione', function () {
    file_put_contents($this->lock, now()->toDateTimeString());
    DB::table('migrations')->insert(['migration' => '2020_01_01_000000_migrazione_tolta_nel_tempo', 'batch' => 1]);

    $this->get('/up')->assertOk()->assertJsonPath('migrazioni_senza_file', 1)->assertJsonPath('migrazioni_pendenti', 0);
});

it('una migrazione da applicare rende l\'installazione non disponibile', function () {
    file_put_contents($this->lock, now()->toDateTimeString());
    $ultima = DB::table('migrations')->orderByDesc('id')->first();
    DB::table('migrations')->where('id', $ultima->id)->delete();

    $this->get('/up')->assertStatus(503)->assertJsonPath('migrazioni_pendenti', 1);
});

it('la rotta sta fuori dal gruppo web e non apre una sessione', function () {
    $rotta = Route::getRoutes()->getByName('salute');

    expect($rotta)->not->toBeNull()
        ->and($rotta->gatherMiddleware())->not->toContain('web');

    $this->get('/up')->assertHeaderMissing('Set-Cookie');
});

it('se il database non risponde dice «errore» senza eccezioni', function () {
    config([
        'database.connections.rotta' => ['driver' => 'sqlite', 'database' => '/percorso/che/non/esiste.sqlite', 'prefix' => ''],
        'database.default' => 'rotta',
    ]);
    DB::setDefaultConnection('rotta');

    try {
        $esito = app(StatoDiSalute::class)->rileva();
    } finally {
        DB::disconnect('rotta');
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');
    }

    expect($esito['sana'])->toBeFalse()
        ->and($esito['database'])->toBe('errore')
        ->and($esito['migrazioni_pendenti'])->toBeNull();
});

it('da un indirizzo pubblico risponde solo con lo stato', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->get('/up')
        ->assertStatus(503)
        ->assertExactJson(['stato' => 'non_disponibile']);

    file_put_contents($this->lock, now()->toDateTimeString());

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->get('/up')
        ->assertOk()
        ->assertExactJson(['stato' => 'ok']);
});

it('da loopback o da rete privata risponde con il dettaglio', function (string $ip) {
    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/up')
        ->assertStatus(503)
        ->assertJsonPath('installata', false)
        ->assertJsonPath('database', 'ok');
})->with(['127.0.0.1', '10.0.1.5', '172.18.0.3', '192.168.1.20']);
