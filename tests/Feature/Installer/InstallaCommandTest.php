<?php

use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/*
 * `kondomanager:installa` (alias `km:install`), 1.11.0-beta.32: l'installazione senza wizard.
 *
 * Le password dell'amministratore hanno dieci caratteri o più: dalla Coda 262 (decisione 127.1) è
 * il minimo anche qui, e le password dei test lo rispettano (`segreta-10`, dieci caratteri; nove
 * caratteri sono il caso rifiutato).
 *
 * Il database della suite è già migrato da RefreshDatabase, e un'installazione vera parte dal
 * vuoto: i test che contano girano su un file sqlite nuovo, reso connessione predefinita per la
 * durata del test. La transazione della suite resta sulla connessione `sqlite` e non c'entra.
 *
 * Un'installazione fatta resta com'è. Se la lettura che dice se c'è un amministratore non riesce,
 * il comando esce con errore senza scrivere, come quando il database non risponde: «non so» non
 * vale «no». E su un database che ha già le tabelle, della cache si toglie solo quella dei permessi.
 * In questi test la manutenzione sta dove la tiene un'istanza in un container: nella cache, sulla
 * tabella `cache` del database (`APP_MAINTENANCE_DRIVER=cache`, come lo imposta chi orchestra i
 * container, sul negozio `database`, che è quello di serie; il Dockerfile mette `CACHE_STORE=database`).
 */

function databaseVuotoPerInstallazione(): string
{
    $file = sys_get_temp_dir().'/km-installa-'.uniqid().'.sqlite';
    touch($file);

    config([
        'database.connections.installa' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'database.default' => 'installa',
    ]);
    DB::setDefaultConnection('installa');

    return $file;
}

beforeEach(function () {
    $this->lock = sys_get_temp_dir().'/km-installa-'.uniqid().'.lock';
    config([
        'installer.options.lock_file' => $this->lock,
        'installer.requirements.link_storage' => false,
    ]);
    $this->attendi = ['--attendi' => 0];
});

afterEach(function () {
    // RefreshDatabase legge la connessione predefinita anche in chiusura: va rimessa a posto
    // PRIMA, o la sua transazione su `sqlite` non viene annullata e il test dopo trova le
    // tabelle già presenti.
    if (config('database.default') === 'installa') {
        DB::disconnect('installa');
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');
    }
    File::delete($this->lock);
    if (isset($this->dbFile)) {
        File::delete($this->dbFile);
    }
    if (isset($this->storage)) {
        File::deleteDirectory($this->storage);
    }
});

/**
 * L'istanza in manutenzione, con `artisan down` come la chiude chi la orchestra, e la manutenzione
 * nella tabella `cache` della connessione della suite. `artisan down` scrive anche
 * `framework/maintenance.php`: `storage` sta in una cartella temporanea, perché quella vera è
 * dell'installazione che gira su questa macchina.
 */
function istanzaInManutenzioneNelDatabase($test): void
{
    $test->storage = sys_get_temp_dir().'/km-installa-storage-'.uniqid();
    File::ensureDirectoryExists($test->storage.'/framework');
    app()->useStoragePath($test->storage);

    config([
        'cache.default' => 'database',
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'database',
    ]);
    app()->forgetInstance(MaintenanceModeManager::class);

    $test->artisan('down', ['--redirect' => 'https://pagina.example/istanza-chiusa'])->assertSuccessful();

    expect(app()->isDownForMaintenance())->toBeTrue();
}

/**
 * La prossima interrogazione che contiene `$frammento` non riesce, una volta sola (senza frammento,
 * la prossima qualunque): dopo, il database risponde come sempre. L'eccezione è quella che il
 * database dà quando rifiuta una connessione in più.
 */
function letturaCheNonRiesceUnaVolta(?string $frammento): void
{
    $giaFallita = false;

    DB::connection()->beforeExecuting(function (string $query) use (&$giaFallita, $frammento) {
        if (! $giaFallita && ($frammento === null || str_contains($query, $frammento))) {
            $giaFallita = true;

            throw new QueryException(DB::getDefaultConnection(), $query, [], new PDOException('SQLSTATE[HY000] [1040] Too many connections'));
        }
    });
}

/** Esegue `$fai` con le variabili `INSTALL_*` date, come le trova il comando nel container, e le rimette com'erano. */
function conLeVariabiliDiInstallazione(array $variabili, Closure $fai): void
{
    $vecchie = [];
    foreach ($variabili as $k => $v) {
        $vecchie[$k] = $_ENV[$k] ?? null;
        $_ENV[$k] = $_SERVER[$k] = $v;
    }

    try {
        $fai();
    } finally {
        foreach ($vecchie as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
            } else {
                $_ENV[$k] = $_SERVER[$k] = $v;
            }
        }
    }
}

function nomeDelloStudioSalvato(): mixed
{
    return DB::table('settings')->where('group', 'general')->where('name', 'app_name')->value('payload');
}

it('installa da un database vuoto con i dati dell\'amministratore, senza l\'utente di comodo', function () {
    $this->dbFile = databaseVuotoPerInstallazione();
    expect(Schema::hasTable('users'))->toBeFalse();

    $this->artisan('kondomanager:installa', $this->attendi + [
        '--admin-nome' => 'Mario Rossi',
        '--admin-email' => 'mario@esempio.it',
        '--admin-password' => 'segreta-01',
        '--nome' => 'Studio Rossi',
        '--lingua' => 'it',
    ])->assertSuccessful();

    $admin = User::where('email', 'mario@esempio.it')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->name)->toBe('Mario Rossi')
        ->and(Hash::check('segreta-01', $admin->password))->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->hasRole('amministratore'))->toBeTrue()
        ->and(User::where('email', 'admin@km.com')->exists())->toBeFalse()
        ->and(User::count())->toBe(1)
        ->and(app(GeneralSettings::class)->app_name)->toBe('Studio Rossi')
        ->and(file_exists($this->lock))->toBeTrue()
        ->and(DB::table('categorie_evento')->count())->toBeGreaterThan(0);
});

it('legge i dati dell\'amministratore dalle variabili INSTALL_* quando mancano le opzioni', function () {
    $this->dbFile = databaseVuotoPerInstallazione();

    $vecchie = [];
    foreach (['INSTALL_ADMIN_NAME' => 'Anna Bianchi', 'INSTALL_ADMIN_EMAIL' => 'anna@esempio.it', 'INSTALL_ADMIN_PASSWORD' => 'segreta-02'] as $k => $v) {
        $vecchie[$k] = $_ENV[$k] ?? null;
        $_ENV[$k] = $_SERVER[$k] = $v;
    }

    try {
        $this->artisan('km:install', $this->attendi)->assertSuccessful();
    } finally {
        foreach ($vecchie as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
            } else {
                $_ENV[$k] = $_SERVER[$k] = $v;
            }
        }
    }

    $admin = User::where('email', 'anna@esempio.it')->first();
    expect($admin?->name)->toBe('Anna Bianchi')
        ->and(Hash::check('segreta-02', $admin->password))->toBeTrue()
        ->and(User::count())->toBe(1);
});

it('senza i dati dell\'amministratore si ferma prima di scrivere: nessuna tabella, nessun lock', function () {
    $this->dbFile = databaseVuotoPerInstallazione();

    $this->artisan('kondomanager:installa', $this->attendi)
        ->expectsOutputToContain('INSTALL_ADMIN_EMAIL')
        ->assertExitCode(2);

    expect(Schema::hasTable('users'))->toBeFalse()
        ->and(Schema::hasTable('migrations'))->toBeFalse()
        ->and(file_exists($this->lock))->toBeFalse();
});

it('rifiuta una password corta prima di scrivere e non la stampa', function () {
    $this->dbFile = databaseVuotoPerInstallazione();

    $this->artisan('kondomanager:installa', $this->attendi + [
        '--admin-email' => 'mario@esempio.it',
        '--admin-password' => 'corta',
    ])
        ->doesntExpectOutputToContain('corta')
        ->assertExitCode(2);

    expect(Schema::hasTable('users'))->toBeFalse();
});

it('Coda 262 — rifiuta una password di nove caratteri prima di scrivere, e ne accetta una di dieci', function () {
    // Decisione 127.1: il minimo di dieci caratteri vale anche per l'amministratore dell'installazione.
    $this->dbFile = databaseVuotoPerInstallazione();

    $this->artisan('kondomanager:installa', $this->attendi + [
        '--admin-email' => 'mario@esempio.it',
        '--admin-password' => 'segreta-1',
    ])->assertExitCode(2);

    expect(Schema::hasTable('users'))->toBeFalse()
        ->and(Schema::hasTable('migrations'))->toBeFalse();

    $this->artisan('kondomanager:installa', $this->attendi + [
        '--admin-email' => 'mario@esempio.it',
        '--admin-password' => 'segreta-10',
    ])->assertSuccessful();

    expect(Hash::check('segreta-10', User::where('email', 'mario@esempio.it')->first()?->password))->toBeTrue();
});

it('una seconda esecuzione non fa nulla: il lock la ferma', function () {
    $this->dbFile = databaseVuotoPerInstallazione();
    $opzioni = $this->attendi + ['--admin-email' => 'mario@esempio.it', '--admin-password' => 'segreta-01'];

    $this->artisan('kondomanager:installa', $opzioni)->assertSuccessful();
    $primaScrittura = filemtime($this->lock);

    $this->artisan('kondomanager:installa', $opzioni + ['--admin-password' => 'un-altra-password'])
        ->expectsOutputToContain('già installato')
        ->assertSuccessful();

    $admin = User::where('email', 'mario@esempio.it')->firstOrFail();
    expect(Hash::check('segreta-01', $admin->password))->toBeTrue()
        ->and(User::count())->toBe(1)
        ->and(filemtime($this->lock))->toBe($primaScrittura);
});

it('su un database già installato senza lock scrive solo il lock', function () {
    // La suite ha già migrato e seminato `sqlite`: è un'installazione fatta dal wizard o dai sorgenti.
    Role::findOrCreate('amministratore', 'web');
    $esistente = User::factory()->create(['email' => 'gia@esempio.it']);
    $esistente->assignRole('amministratore');

    $this->artisan('kondomanager:installa', $this->attendi + [
        '--admin-email' => 'nuovo@esempio.it',
        '--admin-password' => 'segreta-01',
    ])
        ->expectsOutputToContain('esiste un amministratore')
        ->assertSuccessful();

    expect(User::where('email', 'nuovo@esempio.it')->exists())->toBeFalse()
        ->and(file_exists($this->lock))->toBeTrue();
});

it('se il database non risponde non scrive nulla ed esce con errore', function () {
    config([
        'database.connections.installa' => [
            'driver' => 'sqlite',
            'database' => '/percorso/che/non/esiste/km.sqlite',
            'prefix' => '',
        ],
        'database.default' => 'installa',
    ]);
    DB::setDefaultConnection('installa');

    $this->artisan('kondomanager:installa', $this->attendi + [
        '--admin-email' => 'mario@esempio.it',
        '--admin-password' => 'segreta-01',
    ])
        ->expectsOutputToContain('non risponde')
        ->assertFailed();

    expect(file_exists($this->lock))->toBeFalse();
});

it('un errore di lettura ferma l\'installazione senza scrivere: la manutenzione, gli utenti e il nome restano quelli di prima', function (string $emailAmministratore, ?string $letturaCheNonRiesce) {
    // Un'installazione già fatta, chiusa in manutenzione e senza il file di lock; le variabili `INSTALL_*` sono nell'ambiente.
    Role::findOrCreate('amministratore', 'web');
    User::factory()->create(['email' => $emailAmministratore])->assignRole('amministratore');
    $utenti = User::count();
    config(['app.name' => 'Nome del primo avvio']);
    $nomeDelloStudio = nomeDelloStudioSalvato();
    istanzaInManutenzioneNelDatabase($this);

    letturaCheNonRiesceUnaVolta($letturaCheNonRiesce);

    conLeVariabiliDiInstallazione([
        'INSTALL_ADMIN_NAME' => 'Amministratore del primo avvio',
        'INSTALL_ADMIN_EMAIL' => 'primo-avvio@esempio.it',
        'INSTALL_ADMIN_PASSWORD' => 'segreta-01',
    ], fn () => $this->artisan('kondomanager:installa', $this->attendi)
        ->expectsOutputToContain('nessuna scrittura')
        ->assertExitCode(1));

    expect(app()->isDownForMaintenance())->toBeTrue()
        ->and(User::count())->toBe($utenti)
        ->and(User::role('amministratore')->pluck('email')->all())->toBe([$emailAmministratore])
        ->and(nomeDelloStudioSalvato())->toBe($nomeDelloStudio)
        ->and(file_exists($this->lock))->toBeFalse();
})->with([
    'la lettura dei ruoli, con l\'amministratore dell\'installazione' => ['primo-avvio@esempio.it', 'model_has_roles'],
    'la lettura dei ruoli, con un altro amministratore' => ['studio@esempio.it', 'model_has_roles'],
    'la lettura delle tabelle, con un altro amministratore' => ['studio@esempio.it', null],
]);

it('su un database installato la cache non si svuota: la manutenzione resta', function () {
    Role::findOrCreate('amministratore', 'web');
    User::factory()->create(['email' => 'gia@esempio.it'])->assignRole('amministratore');
    istanzaInManutenzioneNelDatabase($this);

    $this->artisan('kondomanager:installa', $this->attendi + [
        '--admin-email' => 'nuovo@esempio.it',
        '--admin-password' => 'segreta-01',
    ])
        ->expectsOutputToContain('esiste un amministratore')
        ->assertSuccessful();

    expect(app()->isDownForMaintenance())->toBeTrue()
        ->and(User::where('email', 'nuovo@esempio.it')->exists())->toBeFalse()
        ->and(file_exists($this->lock))->toBeTrue();
});

it('su un database con le tabelle e senza amministratore l\'installazione toglie solo la cache dei permessi: la manutenzione resta', function () {
    // La lettura riesce e dice «nessun amministratore» (il ruolo c'è, nessun utente lo ha): il
    // comando installa, e la cache che trova è quella dell'installazione che gira.
    Role::findOrCreate('amministratore', 'web');
    User::factory()->create(['email' => 'utente@esempio.it']);
    istanzaInManutenzioneNelDatabase($this);

    $this->artisan('kondomanager:installa', $this->attendi + [
        '--admin-email' => 'mario@esempio.it',
        '--admin-password' => 'segreta-01',
    ])->assertSuccessful();

    expect(app()->isDownForMaintenance())->toBeTrue()
        ->and(User::role('amministratore')->pluck('email')->all())->toBe(['mario@esempio.it']);
});

it('un errore dopo la creazione dell\'amministratore non lascia niente: il secondo avvio rifà tutto', function () {
    $this->dbFile = databaseVuotoPerInstallazione();
    $opzioni = $this->attendi + ['--admin-email' => 'mario@esempio.it', '--admin-password' => 'segreta-01'];

    // Un seeder che esplode dopo che l'amministratore è stato creato.
    config(['installer.requirements.seeding.classes' => [SeederCheEsplode::class]]);

    expect(fn () => $this->artisan('kondomanager:installa', $opzioni))->toThrow(RuntimeException::class);

    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(User::count())->toBe(0)
        ->and(DB::table('roles')->count())->toBe(0)
        ->and(file_exists($this->lock))->toBeFalse();

    // Secondo avvio, con il seeding normale: non «già presente», ma un'installazione vera.
    config(['installer.requirements.seeding.classes' => []]);
    $this->artisan('kondomanager:installa', $opzioni)->assertSuccessful();

    expect(User::where('email', 'mario@esempio.it')->exists())->toBeTrue()
        ->and(DB::table('categorie_evento')->count())->toBeGreaterThan(0)
        ->and(file_exists($this->lock))->toBeTrue();
});

it('l\'errore che risale non porta con sé la password', function () {
    $this->dbFile = databaseVuotoPerInstallazione();
    config(['installer.requirements.seeding.classes' => [SeederCheEsplode::class]]);

    try {
        $this->artisan('kondomanager:installa', $this->attendi + ['--admin-email' => 'mario@esempio.it', '--admin-password' => 'SegretaXYZ99']);
        $this->fail('doveva fallire');
    } catch (RuntimeException $e) {
        expect($e->getTraceAsString())->not->toContain('SegretaXYZ99')
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->toContain('nessun dato scritto');
    }
});

it('un lock rimasto su un database vuoto non vale: si installa', function () {
    $this->dbFile = databaseVuotoPerInstallazione();
    file_put_contents($this->lock, now()->toDateTimeString());

    $this->artisan('kondomanager:installa', $this->attendi + ['--admin-email' => 'mario@esempio.it', '--admin-password' => 'segreta-01'])
        ->expectsOutputToContain('database vuoto')
        ->assertSuccessful();

    expect(User::where('email', 'mario@esempio.it')->exists())->toBeTrue();
});

it('INSTALL_ADMIN_NAME vuota vale come assente', function () {
    $this->dbFile = databaseVuotoPerInstallazione();
    $vecchio = $_ENV['INSTALL_ADMIN_NAME'] ?? null;
    $_ENV['INSTALL_ADMIN_NAME'] = $_SERVER['INSTALL_ADMIN_NAME'] = '';

    try {
        $this->artisan('kondomanager:installa', $this->attendi + ['--admin-email' => 'mario@esempio.it', '--admin-password' => 'segreta-01'])
            ->assertSuccessful();
    } finally {
        if ($vecchio === null) {
            unset($_ENV['INSTALL_ADMIN_NAME'], $_SERVER['INSTALL_ADMIN_NAME']);
        } else {
            $_ENV['INSTALL_ADMIN_NAME'] = $_SERVER['INSTALL_ADMIN_NAME'] = $vecchio;
        }
    }

    expect(User::where('email', 'mario@esempio.it')->value('name'))->toBe('Amministratore');
});

class SeederCheEsplode extends Seeder
{
    public function run(): void
    {
        throw new RuntimeException('connessione caduta durante il seed');
    }
}
