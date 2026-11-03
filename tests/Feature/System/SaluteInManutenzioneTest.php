<?php

/**
 * # In manutenzione `/up` dice lo stato vero (1.11.0-beta.46, Coda 224)
 *
 * KondoCloud chiude un'istanza in attesa di pagamento con `php artisan down --redirect=…`, con la
 * manutenzione nel database dell'istanza (`APP_MAINTENANCE_DRIVER=cache`,
 * `APP_MAINTENANCE_STORE=database`) perché un riavvio non la tolga. Dalla 1.11.0-beta.32 alla .45 `/up`
 * stava dietro il middleware della manutenzione come ogni altra pagina (fino alla .31 era la rotta
 * `health:` di Laravel, che Laravel esclude da solo): rispondeva il rimando (302) senza mai arrivare
 * a `SaluteController`, e il controllo di Coolify (`curl -f`, che passa con qualunque risposta sotto
 * 400) diceva «sano» anche con migrazioni da applicare; con il database giù rispondeva 500, l'errore
 * della lettura della manutenzione e non lo stato dell'installazione.
 *
 * ## Cosa presidiano questi test
 *
 * 1. In manutenzione `/up` risponde come fuori: 200 a installazione sana, 503 con una migrazione da
 *    applicare o con il database che non risponde.
 * 2. Con il database giù `/up` non legge nemmeno la manutenzione: con il driver `cache` su
 *    `database` quella lettura fallirebbe prima di arrivare al controllo di salute.
 * 3. Le altre pagine restano chiuse: la manutenzione non si apre per chi conosce `/up`, e
 *    l'elenco delle eccezioni è `/up` e basta.
 * 4. Con la pagina prerenderizzata (`--render`, driver `file`) risponde il file
 *    `framework/maintenance.php`, che `public/index.php` esegue prima di avviare l'applicazione e
 *    che quindi non passa dal middleware: legge l'elenco delle eccezioni dal file `framework/down`,
 *    dove lo scrive `artisan down`. Il test lo esegue in un processo a sé, per `/up` e per due
 *    indirizzi che devono restare chiusi.
 * 5. Fuori dalla manutenzione le pagine che la leggono rispondono come prima.
 *
 * `storage` sta in una cartella temporanea: `artisan down` scrive `framework/maintenance.php` (e,
 * con il driver `file`, `framework/down`), e la cartella vera è quella dell'installazione che gira
 * su questa macchina.
 */

use App\Services\System\StatoDiSalute;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const PAGINA_DI_CORTESIA = 'https://pagina.example/istanza-chiusa';

beforeEach(function () {
    $this->lock = sys_get_temp_dir().'/km-salute-'.uniqid().'.lock';
    config(['installer.options.lock_file' => $this->lock]);

    $this->storage = sys_get_temp_dir().'/km-manutenzione-'.uniqid();
    File::ensureDirectoryExists($this->storage.'/framework');
    $this->app->useStoragePath($this->storage);

    // Come KondoCloud: la manutenzione nel database dell'istanza.
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
    $this->app->forgetInstance(MaintenanceModeManager::class);
});

afterEach(function () {
    File::delete($this->lock);
    File::deleteDirectory($this->storage);
});

it('in manutenzione /up risponde 200 a installazione sana', function () {
    file_put_contents($this->lock, now()->toDateTimeString());
    $this->artisan('down', ['--redirect' => PAGINA_DI_CORTESIA])->assertSuccessful();

    expect($this->app->isDownForMaintenance())->toBeTrue();

    $this->get('/up')->assertOk()->assertJson(['stato' => 'ok', 'migrazioni_pendenti' => 0]);
});

it('in manutenzione /up risponde 503 con una migrazione da applicare', function () {
    file_put_contents($this->lock, now()->toDateTimeString());
    $ultima = DB::table('migrations')->orderByDesc('id')->first();
    DB::table('migrations')->where('id', $ultima->id)->delete();
    $this->artisan('down', ['--redirect' => PAGINA_DI_CORTESIA])->assertSuccessful();

    $this->get('/up')->assertStatus(503)->assertJsonPath('migrazioni_pendenti', 1);
});

it('in manutenzione con il database giù /up risponde 503 senza leggere la manutenzione', function () {
    file_put_contents($this->lock, now()->toDateTimeString());
    $this->artisan('down', ['--redirect' => PAGINA_DI_CORTESIA])->assertSuccessful();

    config([
        'database.connections.rotta' => ['driver' => 'sqlite', 'database' => '/percorso/che/non/esiste.sqlite', 'prefix' => ''],
        'database.default' => 'rotta',
    ]);
    DB::setDefaultConnection('rotta');
    // La controprova qui sotto finisce in un errore: che non vada nel log vero della macchina.
    config(['logging.default' => 'null']);
    // Il negozio della cache tiene la connessione con cui è nato: senza ricrearlo la manutenzione
    // si leggerebbe ancora dal database buono, e il test non proverebbe niente.
    Cache::forgetDriver('database');

    try {
        $risposta = $this->get('/up');
        // La controprova: una pagina che legge la manutenzione, con il database giù, fallisce.
        $altra = $this->get('/login');
    } finally {
        DB::disconnect('rotta');
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');
    }

    $risposta->assertStatus(503)->assertJsonPath('database', 'errore');
    $altra->assertStatus(500);
    // E il 500 viene dalla lettura della manutenzione, non da un punto più avanti della pagina.
    expect($altra->exception)->toBeInstanceOf(QueryException::class)
        ->and($altra->exception->getSql())->toStartWith('select * from "cache"');
});

it('in manutenzione le altre pagine restano chiuse', function () {
    file_put_contents($this->lock, now()->toDateTimeString());
    $this->artisan('down', ['--redirect' => PAGINA_DI_CORTESIA])->assertSuccessful();

    $this->get('/login')->assertRedirect(PAGINA_DI_CORTESIA);
    $this->getJson('/login')->assertStatus(503);
    $this->get('/up/altro')->assertRedirect(PAGINA_DI_CORTESIA);

    // Un'eccezione in più (`/system/run-scheduler`, `ripristino/*`) aprirebbe in silenzio altre
    // pagine senza che le due richieste qui sopra se ne accorgano: l'elenco è `/up` e basta.
    $eccezioni = app(PreventRequestsDuringMaintenance::class)->getExcludedPaths();
    expect(array_map(fn (string $eccezione) => trim($eccezione, '/'), $eccezioni))->toBe(['up']);
});

it('con la pagina prerenderizzata il file letto da public/index.php lascia passare /up e solo /up', function () {
    // È la strada che non passa dal middleware: driver `file` e `--render`, e il file
    // `framework/maintenance.php` risponde prima che l'applicazione parta. Si esegue in un processo
    // a sé, come lo esegue `public/index.php`: se lascia passare la richiesta, si arriva all'eco.
    config(['app.maintenance.driver' => 'file']);
    $this->app->forgetInstance(MaintenanceModeManager::class);
    $this->artisan('down', ['--render' => 'errors::503'])->assertSuccessful();

    $passa = function (string $indirizzo) {
        $codice = '$_SERVER["REQUEST_URI"] = '.var_export($indirizzo, true).'; '
            .'require '.var_export($this->storage.'/framework/maintenance.php', true).'; echo "PASSA";';

        return str_ends_with(Process::run([PHP_BINARY, '-r', $codice])->output(), 'PASSA');
    };

    expect(File::exists($this->storage.'/framework/down'))->toBeTrue()
        ->and($passa('/up'))->toBeTrue()
        ->and($passa('/login'))->toBeFalse()
        ->and($passa('/up/altro'))->toBeFalse();
});

it('fuori dalla manutenzione /up e le pagine che la leggono rispondono come prima', function () {
    file_put_contents($this->lock, now()->toDateTimeString());

    expect($this->app->isDownForMaintenance())->toBeFalse()
        ->and(app(StatoDiSalute::class)->rileva()['sana'])->toBeTrue();

    $this->get('/up')->assertOk()->assertJson(['stato' => 'ok']);
    // `/up` non legge la manutenzione; `/login` sì, dal database: deve trovarla spenta e aprirsi.
    $this->get('/login')->assertOk();
});
