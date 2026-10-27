<?php

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\Database\SqliteDumper;
use App\Services\Backup\Support\StepBudget;
use App\Services\Restore\DatabaseImporter;
use App\Services\Restore\MessaggiDelRipristino;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use App\Services\Restore\SqlDumpTokenizer;
use App\Services\System\SystemFinalizer;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Il ripristino con il database a metà (decisione 134.2): le sessioni nel database, le righe di sessioni e cache
 * scritte durante l'import, il recupero con la password dalla pagina 503, «Riprendi» dopo un errore a metà di un
 * passo dei dati.
 *
 * Girano le rotte vere (step, stato, «riprendi», «annulla», la pagina dell'esito, la 503, l'avvio), RestoreManager e
 * il ciclo vero di DatabaseImporter::importStep. Il database dell'installazione è un file sqlite, perché l'importatore
 * si ricollega a ogni passo (un database in memoria sparirebbe). Cambiano solo tre cose rispetto a MySQL, tutte
 * fuori dal ciclo dell'importatore:
 *  - il wipe toglie le tabelle con le istruzioni di sqlite;
 *  - il preambolo di sessione di MySQL (`SET NAMES`, `SET FOREIGN_KEY_CHECKS`, …) non si esegue: le chiavi esterne
 *    sono già spente sulla connessione;
 *  - il budget del passo si conta in istruzioni invece che in secondi: dopo N istruzioni risulta esaurito, e il passo
 *    si ferma come allo scadere dei venti secondi.
 * Il dump ha la forma di MySqlDumper: tutte le strutture in ordine di nome, poi tutti i dati in ordine di nome, una
 * riga per istruzione.
 */

/** La connessione del database dell'installazione: conta le istruzioni dell'importatore e ne fa fallire una. */
class PdoDelRipristinoDiProva extends PDO
{
    /** Vero solo mentre l'importatore lavora: le altre letture e scritture passano senza essere contate. */
    public static bool $attivo = false;

    public static int $perPasso = PHP_INT_MAX;

    public static int $nelPasso = 0;

    /** Istruzioni eseguite dall'importatore, dall'inizio. */
    public static int $eseguite = 0;

    /** @var array<int, int> istruzioni eseguite a ogni passo */
    public static array $passi = [];

    /** Il numero d'ordine (dall'inizio dell'import) dell'istruzione che fallisce, una volta sola. */
    public static ?int $guastoAllIstruzione = null;

    private static ?StepBudget $budget = null;

    public static function azzera(): void
    {
        self::$attivo = false;
        self::$perPasso = PHP_INT_MAX;
        self::$nelPasso = 0;
        self::$eseguite = 0;
        self::$passi = [];
        self::$guastoAllIstruzione = null;
        self::$budget = null;
    }

    public static function inizioPasso(StepBudget $budget): void
    {
        self::$attivo = true;
        self::$nelPasso = 0;
        self::$budget = $budget;
        self::$passi[] = 0;
    }

    public function exec(string $statement): int|false
    {
        if (! self::$attivo) {
            return parent::exec($statement);
        }

        if (preg_match('/^\s*SET\s/i', $statement)) {
            return 0;
        }

        if (self::$guastoAllIstruzione !== null && self::$eseguite + 1 === self::$guastoAllIstruzione) {
            self::$guastoAllIstruzione = null;

            throw new PDOException('Errore passeggero del database');
        }

        $esito = parent::exec($statement);

        self::$eseguite++;
        self::$nelPasso++;
        self::$passi[array_key_last(self::$passi)]++;

        if (self::$nelPasso >= self::$perPasso && self::$budget !== null) {
            (fn () => $this->startedAt = microtime(true) - 3600)->call(self::$budget);
        }

        return $esito;
    }
}

/** L'importatore vero, con il wipe di sqlite e il budget a istruzioni. */
class ImportatoreDelRipristinoDiProva extends DatabaseImporter
{
    public function wipe(): void
    {
        $pdo = DB::connection()->getPdo();

        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $tabella) {
            $pdo->exec('DROP TABLE IF EXISTS "'.$tabella.'"');
        }
    }

    public function importStep(string $dumpPath, array $checkpoint, StepBudget $budget): array
    {
        PdoDelRipristinoDiProva::inizioPasso($budget);

        try {
            return parent::importStep($dumpPath, $checkpoint, $budget);
        } finally {
            PdoDelRipristinoDiProva::$attivo = false;
        }
    }
}

function rdmCartella(): string
{
    return storage_path('framework/testing/ripristino-a-meta-'.(ParallelTesting::token() ?: 'serial'));
}

/**
 * Il database dell'installazione in un file, con lo schema e i dati del database dei test (le migrazioni), una
 * tabella in testa all'ordine dei nomi per avere dati da importare, e lo stesso file come connessione predefinita.
 */
function rdmInstallazione(string $driverSessioni): void
{
    File::ensureDirectoryExists(rdmCartella());
    $file = rdmCartella().'/installazione.sqlite';
    @unlink($file);
    touch($file);

    $memoria = DB::connection();
    $verso = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $verso->exec('PRAGMA foreign_keys = OFF');

    foreach ($memoria->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $tabella) {
        $verso->exec($tabella->sql);

        foreach ($memoria->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$tabella->name]) as $indice) {
            $verso->exec($indice->sql);
        }

        foreach ($memoria->table($tabella->name)->get() as $riga) {
            $riga = (array) $riga;
            $verso->prepare('INSERT INTO "'.$tabella->name.'" ("'.implode('","', array_keys($riga)).'") VALUES ('.implode(',', array_fill(0, count($riga), '?')).')')
                ->execute(array_values($riga));
        }
    }

    $verso->exec('CREATE TABLE "aa_righe" ("id" integer primary key not null, "valore" varchar not null)');
    $verso = null;

    config(['database.connections.installazione_di_prova' => [
        'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    DB::extend('installazione_di_prova', function (array $config) use ($file) {
        $pdo = new PdoDelRipristinoDiProva('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = OFF');

        return new SQLiteConnection($pdo, $file, '', $config);
    });
    DB::setDefaultConnection('installazione_di_prova');

    config(['session.driver' => $driverSessioni, 'session.files' => rdmCartella().'/sessioni']);
    File::ensureDirectoryExists(rdmCartella().'/sessioni');

    // La finalizzazione cerca gli archivi da registrare nella cartella dei backup: quella dei test.
    config(['filesystems.disks.backups.root' => rdmCartella().'/backups']);
    File::ensureDirectoryExists(rdmCartella().'/backups');
}

/** Il dump nella forma di MySqlDumper, dal database dell'installazione. */
function rdmScriviIlDump(string $percorso): string
{
    $pdo = DB::connection()->getPdo();
    $tabelle = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_OBJ);

    $righe = ['-- KondoManager — backup database', '', 'SET NAMES utf8mb4;', 'SET FOREIGN_KEY_CHECKS=0;', 'SET UNIQUE_CHECKS=0;',
        "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';", "SET time_zone='+00:00';", ''];

    foreach ($tabelle as $t) {
        $righe[] = "-- Struttura tabella `{$t->name}`";
        $righe[] = "DROP TABLE IF EXISTS `{$t->name}`;";
        $righe[] = $t->sql.';';
        foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = '{$t->name}' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $indice) {
            $righe[] = $indice.';';
        }
        $righe[] = '';
    }

    foreach ($tabelle as $t) {
        $righe[] = "-- Dati tabella `{$t->name}`";
        foreach ($pdo->query('SELECT * FROM "'.$t->name.'"')->fetchAll(PDO::FETCH_ASSOC) as $riga) {
            $valori = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($riga));
            $righe[] = 'INSERT INTO `'.$t->name.'` (`'.implode('`, `', array_keys($riga)).'`) VALUES ('.implode(',', $valori).');';
        }
        $righe[] = '';
    }

    $righe[] = 'SET FOREIGN_KEY_CHECKS=1;';

    File::ensureDirectoryExists(dirname($percorso));
    File::put($percorso, implode("\n", $righe)."\n");

    return $percorso;
}

/** Il numero d'ordine delle istruzioni che contano, contato come le conta l'importatore (senza i `SET`). */
function rdmPosizioni(string $dump): array
{
    $n = 0;
    $pos = [];

    foreach ((new SqlDumpTokenizer($dump))->statements() as $s) {
        $sql = ltrim($s['sql']);
        if (preg_match('/^SET\s/i', $sql)) {
            continue;
        }
        $n++;
        foreach (['sessions', 'cache', 'cache_locks', 'users', 'aa_righe', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'] as $tabella) {
            if (preg_match('/^CREATE TABLE\s+["`]?'.$tabella.'["`]?\s*\(/i', $sql)) {
                $pos['crea_'.$tabella] = $n;
            }
            if (! isset($pos['dati_'.$tabella]) && str_starts_with($sql, 'INSERT INTO `'.$tabella.'`')) {
                $pos['dati_'.$tabella] = $n;
            }
        }
        if (! isset($pos['primo_dato']) && str_starts_with($sql, 'INSERT')) {
            $pos['primo_dato'] = $n;
        }
    }
    $pos['totale'] = $n;

    return $pos;
}

/** Ogni richiesta HTTP vera è un processo nuovo: il gestore della sessione non ricorda niente. */
function rdmNuovaRichiesta(): void
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

function rdmAmministratore(string $password = 'la-mia-password'): User
{
    $permesso = \Spatie\Permission\Models\Permission::firstOrCreate([
        'name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web',
    ]);
    $admin = User::factory()->create(['password' => bcrypt($password)]);
    $admin->givePermissionTo($permesso);

    return $admin;
}

function rdmRiempi(int $righe): void
{
    for ($i = 1; $i <= $righe; $i++) {
        DB::table('aa_righe')->insert(['id' => $i, 'valore' => 'riga '.$i]);
    }
}

/**
 * Un ripristino fermo all'inizio di `importing_database` (prima del wipe), con il dump scritto dal database di adesso
 * e lo stato come lo scrive l'avvio. Restituisce il token, l'amministratore e le posizioni del dump.
 */
function rdmRipristinoAvviato(User $admin, string $password = 'la-mia-password'): array
{
    $uuid = 'r-'.Str::lower(Str::random(8));
    $dump = rdmScriviIlDump(rtrim(config('backup.tmp_path'), '/')."/restore-{$uuid}/extracted/db/database.sql");

    $stato = app(RestoreState::class);
    $stato->put([
        'uuid' => $uuid, 'phase' => 'importing_database', 'manifest' => ['contents' => 'db_only'],
        'options' => ['safety_backup' => false, 'adopt_app_key' => false], 'checkpoint' => [], 'outcome' => [],
        'created_by' => $admin->id, 'created_by_password_hash' => $admin->getAuthPassword(),
        'locale' => 'it', 'started_at' => time(),
    ]);
    $token = $stato->issueToken(3600);
    app(RestoreMode::class)->enter($uuid, 'it');

    return [$token, rdmPosizioni($dump)];
}

function rdmImportatore(int $perPasso): void
{
    PdoDelRipristinoDiProva::$perPasso = $perPasso;
    app()->instance(DatabaseImporter::class, new ImportatoreDelRipristinoDiProva);
    test()->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andReturnNull());
}

/** Guida gli step come la pagina dei backup, finché il ripristino finisce, fallisce o una risposta non è 200. */
function rdmGuida($test, string $token, int $massimo = 80, ?Closure $dopoOgniPasso = null): array
{
    $risposte = [];
    for ($i = 0; $i < $massimo; $i++) {
        rdmNuovaRichiesta();
        $r = $test->postJson('/ripristino/step', [], ['X-Restore-Token' => $token]);
        $fase = $r->json('restore.phase');
        $risposte[] = $r->status().' '.($fase ?? substr((string) $r->json('message'), 0, 90));

        if ($r->status() !== 200 || in_array($fase, ['completed', 'failed'], true)) {
            break;
        }

        if ($dopoOgniPasso) {
            $dopoOgniPasso();
        }
    }

    return $risposte;
}

/** La 503 di recupero: lo stato fermo da tre ore, come dopo una finestra chiusa durante l'import. */
function rdmFermoDaTreOre(): void
{
    $file = config('backup.restore.state_file');
    $json = json_decode(File::get($file), true);
    $json['updated_at'] = time() - 3 * 3600;
    File::put($file, json_encode($json));
}

function rdmCookieCifrato(string $nome, string $valore): string
{
    return encrypt(CookieValuePrefix::create($nome, app('encrypter')->getKey()).$valore, false);
}

function rdmErrore(): string
{
    return substr((string) (app(RestoreState::class)->get()['error'] ?? ''), 0, 160);
}

/** Lo stato come lo scriveva l'avvio di una versione precedente: senza la chiave dell'impronta della password. */
function rdmStatoDiPrima(array $altro = []): void
{
    $stato = app(RestoreState::class)->get();
    unset($stato['created_by_password_hash']);
    app(RestoreState::class)->put(array_replace($stato, $altro));
}

/** Il log del server in un file della prova, per leggere che cosa ci finisce. */
function rdmLogDelServer(): string
{
    $file = rdmCartella().'/server.log';
    config(['logging.channels.ripristino_di_prova' => ['driver' => 'single', 'path' => $file, 'level' => 'debug'],
        'logging.default' => 'ripristino_di_prova']);

    return $file;
}

/** La cartella temporanea del ripristino in corso (estrazione e dump). */
function rdmCartellaDelRipristino(): string
{
    return rtrim((string) config('backup.tmp_path'), '/').'/restore-'.app(RestoreState::class)->get()['uuid'];
}

/**
 * L'archivio di un backup del database di adesso (le righe scritte dopo non ci sono), sul disco dei backup, come lo
 * elenca la finestra. Restituisce il backup e le posizioni del dump.
 */
function rdmArchivioNelDisco(): array
{
    Storage::fake('backups');
    config(['filesystems.disks.backups.root' => Storage::disk('backups')->path('')]);
    $dump = rdmScriviIlDump(rdmCartella().'/database.sql');
    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path('archivio.zip'), ZipArchive::CREATE);
    $zip->addFromString('manifest.json', json_encode(['manifest_format' => 1, 'contents' => 'db_only', 'encrypted' => false,
        'app' => ['version' => config('app.version')], 'database' => ['driver' => 'sqlite', 'dump_sha256' => hash_file('sha256', $dump)]]));
    $zip->addFile($dump, 'db/database.sql');
    $zip->close();
    $backup = Backup::create(['uuid' => (string) Str::uuid(), 'filename' => 'archivio.zip', 'disk' => 'backups',
        'status' => BackupStatus::COMPLETED, 'type' => 'db_only']);

    return [$backup, rdmPosizioni($dump)];
}

/** L'avvio dalla finestra dei backup, con i campi che manda la pagina. */
function rdmAvviaDallaFinestra($test, User $admin, Backup $backup, bool $backupDiSicurezza = false)
{
    return $test->actingAs($admin)->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
        'account_password' => 'la-mia-password', 'password' => null, 'safety_backup' => $backupDiSicurezza, 'adopt_app_key' => false,
    ])->assertOk();
}

/**
 * Il tempo che passa: tutti i tempi dello stato indietro di `$secondi`, insieme (l'avvio, la ripresa, la scadenza del
 * token, l'ultimo aggiornamento, il fallimento), come passerebbero davvero. `time()` non segue il viaggio nel tempo.
 */
function rdmTempiIndietro(int $secondi): void
{
    $file = config('backup.restore.state_file');
    $json = json_decode(File::get($file), true);
    foreach (['started_at', 'resumed_at', 'token_expires_at', 'updated_at', 'failed_at'] as $chiave) {
        if (is_int($json[$chiave] ?? null)) {
            $json[$chiave] -= $secondi;
        }
    }
    File::put($file, json_encode($json));
}

afterEach(function () {
    PdoDelRipristinoDiProva::azzera();
    app(RestoreState::class)->clear();
    app(RestoreMode::class)->exit();
    DB::setDefaultConnection('sqlite');
    DB::purge('installazione_di_prova');
    File::deleteDirectory(rdmCartella());
    File::deleteDirectory(dirname((string) config('backup.restore.state_file')).'/.restore-limiter');
});

// --- Le sessioni nel database ----------------------------------------------------------------------------------
// Con le sessioni nel database (l'immagine Docker, le installazioni nate fino alla v1.6.0, un `.env` senza la riga)
// la sessione si legge e si scrive nella tabella `sessions`, che l'import toglie e ricrea. Le quattro rotte del token
// non usano la sessione: stanno fuori dalla sessione, e girano uguali con ogni driver.

test('con le sessioni nel database o su file, il passo che toglie la tabella delle sessioni risponde e il ripristino arriva in fondo', function (string $driver) {
    rdmInstallazione($driver);
    $admin = rdmAmministratore();
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore($pos['crea_sessions'] - 5);

    $risposte = rdmGuida($this, $token);

    expect($risposte[0])->toBe('200 importing_database')
        ->and(count($risposte))->toBeGreaterThan(1)
        ->and(end($risposte))->toBe('200 completed')
        ->and(array_filter($risposte, fn ($r) => ! str_starts_with($r, '200 ')))->toBe([]);
})->with(['database', 'file']);

test('con le sessioni nel database o su file, un errore prima che la tabella delle sessioni torni: stato e «Riprendi» con il token rispondono e il ripristino arriva in fondo', function (string $driver) {
    rdmInstallazione($driver);
    $admin = rdmAmministratore();
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore(PHP_INT_MAX);
    PdoDelRipristinoDiProva::$guastoAllIstruzione = $pos['crea_sessions'] - 10;

    $primo = rdmGuida($this, $token, 1);
    rdmNuovaRichiesta();
    $stato = $this->getJson('/ripristino/stato', ['X-Restore-Token' => $token]);
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);

    expect($primo)->toBe(['200 failed'])
        ->and($stato->status())->toBe(200)
        ->and($stato->json('restore.phase'))->toBe('failed')
        ->and($riprendi->status())->toBe(200);

    $dopo = $riprendi->json('restore.phase') === 'completed' ? ['200 completed'] : rdmGuida($this, $riprendi->json('token'));
    expect(end($dopo))->toBe('200 completed');
})->with(['database', 'file']);

test('con le sessioni nel database o su file, un errore prima che la tabella delle sessioni torni: «Annulla» con il token sblocca il gestionale', function (string $driver) {
    rdmInstallazione($driver);
    $admin = rdmAmministratore();
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore(PHP_INT_MAX);
    PdoDelRipristinoDiProva::$guastoAllIstruzione = $pos['crea_sessions'] - 10;

    expect(rdmGuida($this, $token, 1))->toBe(['200 failed']);

    rdmNuovaRichiesta();
    $this->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token])->assertOk();

    expect(app(RestoreMode::class)->active())->toBeFalse();
})->with(['database', 'file']);

test('con le sessioni nel database o su file, le quattro rotte del token non aprono la sessione: nessun cookie di sessione né XSRF-TOKEN', function (string $driver) {
    rdmInstallazione($driver);
    $admin = rdmAmministratore();
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore(PHP_INT_MAX);
    $nomi = [config('session.cookie'), 'XSRF-TOKEN'];
    $cookie = fn ($r) => array_values(array_intersect($nomi, array_map(fn ($c) => $c->getName(), $r->headers->getCookies())));

    rdmNuovaRichiesta();
    $stato = $this->getJson('/ripristino/stato', ['X-Restore-Token' => $token]);
    rdmNuovaRichiesta();
    $step = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $token]);

    // «Riprendi» e «annulla» su un ripristino fallito, con il token.
    app(RestoreState::class)->merge(['phase' => 'failed', 'failed_phase' => 'finalizing', 'failed_at' => time()]);
    app(RestoreMode::class)->enter('r', 'it');
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andThrow(new RuntimeException('di nuovo')));
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);
    rdmNuovaRichiesta();
    $annulla = $this->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $riprendi->json('token') ?? $token]);

    expect([$stato->status(), $step->status(), $riprendi->status(), $annulla->status()])->toBe([200, 200, 200, 200])
        ->and($cookie($stato))->toBe([])
        ->and($cookie($step))->toBe([])
        ->and($cookie($riprendi))->toBe([])
        ->and($cookie($annulla))->toBe([]);
})->with(['database', 'file']);

test('senza la sessione, le richieste non JSON a «Riprendi» rispondono come prima: il rimando sulla password sbagliata, 404 a modalità spenta, 403 allo stato senza token', function () {
    rdmInstallazione('database');
    $admin = rdmAmministratore();
    rdmRipristinoAvviato($admin);
    app(RestoreState::class)->merge(['phase' => 'failed', 'failed_phase' => 'importing_database', 'failed_at' => time()]);

    rdmNuovaRichiesta();
    $sbagliata = $this->post('/ripristino/riprendi', ['account_password' => 'sbagliata']);
    rdmNuovaRichiesta();
    $senzaToken = $this->get('/ripristino/stato');
    app(RestoreMode::class)->exit();
    rdmNuovaRichiesta();
    $spenta = $this->post('/ripristino/riprendi', ['account_password' => 'la-mia-password']);

    expect($sbagliata->status())->toBe(302)
        ->and($senzaToken->status())->toBe(403)
        ->and($spenta->status())->toBe(404);
});

// --- Le righe di sessioni e cache scritte durante l'import ---------------------------------------------------------
// Il dump di un archivio fatto prima di questa versione porta i dati di `sessions`, `cache` e `cache_locks`. Una riga
// con la stessa chiave scritta durante l'import (la sessione del browser da un'altra pagina, una chiave o un blocco
// della cache dal pianificatore, che gira da riga di comando e non passa dalla modalità ripristino) fermava l'import
// per chiave doppia. Quei dati la finalizzazione li azzera comunque: l'importatore non li scrive.

test('una riga di sessioni, cache o blocchi della cache scritta durante l\'import con la chiave che c\'è nel backup non ferma il ripristino', function (string $caso) {
    rdmInstallazione('database');
    $admin = rdmAmministratore();
    rdmRiempi(400);
    $chiave = Str::random(40);
    $tabella = ['sessione' => 'sessions', 'cache' => 'cache', 'blocco della cache' => 'cache_locks', 'battito del pianificatore' => 'cache'][$caso];
    if ($caso === 'sessione') {
        DB::table('sessions')->insert(['id' => $chiave, 'user_id' => $admin->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'prova',
            'payload' => base64_encode(serialize(['_token' => Str::random(40)])), 'last_activity' => time()]);
    } elseif ($caso === 'cache') {
        DB::table('cache')->insert(['key' => $chiave, 'value' => 's:1:"x";', 'expiration' => time() + 3600]);
    } elseif ($caso === 'blocco della cache') {
        DB::table('cache_locks')->insert(['key' => $chiave, 'owner' => 'pianificatore', 'expiration' => time() + 3600]);
    } else {
        // La cache nel database (l'immagine Docker) e il pianificatore attivo: il backup porta il suo battito.
        rdmCacheNelDatabase();
        rdmBattitoDelPianificatore();
        expect(DB::table('cache')->where('key', 'like', '%system_cron_heartbeat')->exists())->toBeTrue();
    }
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore($pos['crea_sessions'] + 2);
    expect($pos['dati_'.$tabella])->toBeGreaterThan(2 * ($pos['crea_sessions'] + 2));

    $scritta = false;
    $risposte = rdmGuida($this, $token, 80, function () use ($caso, $tabella, $chiave, &$scritta) {
        if ($scritta || ! DB::getSchemaBuilder()->hasTable($tabella)) {
            return;
        }
        $scritta = true;
        if ($caso === 'sessione') {
            // La pagina dell'esito aperta con il cookie della sessione: la sessione si riscrive.
            rdmNuovaRichiesta();
            $this->call('GET', '/ripristino/esito', [], [config('session.cookie') => rdmCookieCifrato(config('session.cookie'), $chiave)]);
            expect(DB::table('sessions')->where('id', $chiave)->exists())->toBeTrue();
        } elseif ($caso === 'cache') {
            DB::table('cache')->insert(['key' => $chiave, 'value' => 's:1:"y";', 'expiration' => time() + 60]);
        } elseif ($caso === 'blocco della cache') {
            DB::table('cache_locks')->insert(['key' => $chiave, 'owner' => 'pianificatore', 'expiration' => time() + 60]);
        } else {
            // Il pianificatore gira da riga di comando, ogni minuto, anche durante il ripristino.
            rdmBattitoDelPianificatore();
            expect(DB::table('cache')->where('key', 'like', '%system_cron_heartbeat')->exists())->toBeTrue();
        }
    });

    expect(rdmErrore())->toBe('')
        ->and($scritta)->toBeTrue()
        ->and(end($risposte))->toBe('200 completed')
        ->and(DB::table('aa_righe')->count())->toBe(400);
})->with(['sessione', 'cache', 'blocco della cache', 'battito del pianificatore']);

/** Il battito del pianificatore (`routes/console.php`), come lo esegue `schedule:run` ogni minuto. */
function rdmBattitoDelPianificatore(): void
{
    $battito = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($evento) => $evento->description === 'system-heartbeat');

    expect($battito)->not->toBeNull();
    $battito->run(app());
}

test('i dati delle sessioni, della cache e dei blocchi della cache restano fuori dai backup nuovi', function () {
    // Il dump scrive la struttura di queste tabelle e non i loro dati (`MySqlDumper`, `backup.dump_exclude_data`):
    // dopo un ripristino la finalizzazione li azzera comunque, e una sessione nel backup è un accesso in un file.
    expect(config('backup.dump_exclude_data'))->toContain('backups', 'sessions', 'cache', 'cache_locks');
});

// --- La coda durante l'import --------------------------------------------------------------------------------------
// Dove la coda gira durante il ripristino (il worker sotto supervisord nell'immagine Docker, con la coda nel database),
// i lavori che l'import riscrive nella tabella `jobs` si eseguirebbero sul database a metà, prima che la finalizzazione
// li svuoti. I dati delle tabelle della coda e dei token per reimpostare la password non tornano nel database: i backup
// nuovi non li hanno, e l'importatore salta quelli degli archivi che li hanno già.

test('i lavori in coda, i lotti, i lavori falliti e i token per reimpostare la password che il backup porta non compaiono nel database durante l\'import', function (string $tabella) {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    $riga = [
        'jobs' => ['queue' => 'default', 'payload' => '{"job":"vecchio"}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
        'job_batches' => ['id' => 'lotto-vecchio', 'name' => 'vecchio', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0,
            'failed_job_ids' => '[]', 'options' => null, 'cancelled_at' => null, 'created_at' => time(), 'finished_at' => null],
        'failed_jobs' => ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"vecchio"}',
            'exception' => 'x', 'failed_at' => now()],
        'password_reset_tokens' => ['email' => $admin->email, 'token' => Hash::make('token-vecchio'), 'created_at' => now()],
    ][$tabella];
    DB::table($tabella)->insert($riga);
    [$token, $pos] = rdmRipristinoAvviato($admin);
    // Il primo passo si ferma subito dopo l'istruzione che scrive i dati della tabella (più uno: la connessione nuova
    // di ogni passo esegue il suo PRAGMA, che si conta).
    rdmImportatore($pos['dati_'.$tabella] + 1);
    expect($pos['dati_'.$tabella])->toBeLessThan($pos['totale']);

    // Dopo ogni passo: quante istruzioni del dump sono state eseguite, e quante righe ha la tabella.
    $controlli = [];
    $risposte = rdmGuida($this, $token, 80, function () use ($tabella, &$controlli) {
        $controlli[] = [PdoDelRipristinoDiProva::$eseguite - count(PdoDelRipristinoDiProva::$passi), DB::table($tabella)->count()];
    });

    expect(end($risposte))->toBe('200 completed')
        ->and($controlli)->not->toBe([])
        ->and($controlli[0])->toBe([$pos['dati_'.$tabella], 0])
        ->and(array_filter($controlli, fn ($c) => $c[1] > 0))->toBe([]);
})->with(['jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens']);

test('i dati della coda e dei token per reimpostare la password restano fuori dai backup nuovi', function () {
    expect(config('backup.dump_exclude_data'))->toContain('jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens');
});

// --- Il recupero con la password dalla 503 -------------------------------------------------------------------------
// Chi chiude la finestra durante l'import ritrova, dopo due ore, la pagina di recupero: «Riprendi» e «Annulla» con la
// password dell'account che ha avviato il ripristino. La password si verifica sull'impronta salvata nello stato
// all'avvio, non nella tabella degli utenti che l'import sta riscrivendo; il limite di cinque prove al minuto resta.

test('dalla 503, «Riprendi» con la password di chi ha avviato il ripristino riesce con l\'import fermo prima della tabella degli utenti', function (string $driver) {
    rdmInstallazione($driver);
    $admin = rdmAmministratore();
    rdmRiempi(50);
    Storage::fake('backups');
    config(['filesystems.disks.backups.root' => Storage::disk('backups')->path('')]);
    rdmImportatore(PHP_INT_MAX);

    // L'archivio vero, avviato dalla finestra dei backup come la manda la pagina.
    $dump = rdmScriviIlDump(rdmCartella().'/database.sql');
    $pos = rdmPosizioni($dump);
    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path('archivio.zip'), ZipArchive::CREATE);
    $zip->addFromString('manifest.json', json_encode(['manifest_format' => 1, 'contents' => 'db_only', 'encrypted' => false,
        'app' => ['version' => config('app.version')], 'database' => ['driver' => 'sqlite', 'dump_sha256' => hash_file('sha256', $dump)]]));
    $zip->addFile($dump, 'db/database.sql');
    $zip->close();
    $backup = Backup::create(['uuid' => (string) Str::uuid(), 'filename' => 'archivio.zip', 'disk' => 'backups',
        'status' => \App\Enums\BackupStatus::COMPLETED, 'type' => 'db_only']);

    $avvio = $this->actingAs($admin)->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
        'account_password' => 'la-mia-password', 'password' => null, 'safety_backup' => false, 'adopt_app_key' => false,
    ])->assertOk();

    // Un passo, poi la finestra si chiude: l'import è fermo prima della tabella degli utenti.
    PdoDelRipristinoDiProva::$perPasso = $pos['crea_users'] - 3;
    expect(rdmGuida($this, $avvio->json('token'), 1))->toBe(['200 importing_database'])
        ->and(DB::getSchemaBuilder()->hasTable('users'))->toBeFalse();
    rdmFermoDaTreOre();
    PdoDelRipristinoDiProva::$perPasso = PHP_INT_MAX;

    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password']);

    expect($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($riprendi->status())->toBe(200, (string) $riprendi->json('message'));

    $dopo = $riprendi->json('restore.phase') === 'completed' ? ['200 completed'] : rdmGuida($this, $riprendi->json('token'));
    expect(end($dopo))->toBe('200 completed')
        ->and(DB::table('aa_righe')->count())->toBe(50);
})->with(['file', 'database']);

test('dalla 503, «Riprendi» con la password giusta riesce anche con la tabella degli utenti creata e ancora vuota', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore($pos['crea_users'] + 2);
    rdmGuida($this, $token, 1);
    rdmFermoDaTreOre();
    expect(DB::table('users')->count())->toBe(0);

    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password']);

    expect($riprendi->status())->toBe(200, (string) ($riprendi->json('errors.account_password.0') ?? $riprendi->json('message')))
        ->and($riprendi->json('errors'))->toBeNull();
});

test('dalla 503, con la cache nel database e l\'import fermo prima della tabella della cache, «Riprendi» con la password riesce', function () {
    rdmInstallazione('file');
    rdmCacheNelDatabase();
    $admin = rdmAmministratore();
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore($pos['crea_cache'] - 3);
    rdmGuida($this, $token, 1);
    rdmFermoDaTreOre();
    expect(DB::getSchemaBuilder()->hasTable('cache'))->toBeFalse();

    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password']);

    expect($riprendi->status())->toBe(200, (string) $riprendi->json('message'));
});

test('dalla 503, con la cache nel database e la tabella della cache tolta, il limite resta: cinque password sbagliate al minuto, la sesta si ferma; il token passa', function () {
    rdmInstallazione('file');
    rdmCacheNelDatabase();
    $admin = rdmAmministratore();
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore($pos['crea_cache'] - 3);
    rdmGuida($this, $token, 1);
    app(RestoreState::class)->merge(['phase' => 'failed', 'failed_phase' => 'importing_database', 'failed_at' => time()]);

    $esiti = [];
    for ($i = 0; $i < 6; $i++) {
        rdmNuovaRichiesta();
        $esiti[] = $this->postJson('/ripristino/annulla', ['account_password' => 'sbagliata-'.$i])->status();
    }
    rdmNuovaRichiesta();
    $giustaDopo = $this->postJson('/ripristino/annulla', ['account_password' => 'la-mia-password'])->status();
    rdmNuovaRichiesta();
    $conToken = $this->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token])->status();

    expect($esiti)->toBe([422, 422, 422, 422, 422, 429])
        ->and($giustaDopo)->toBe(429)
        ->and($conToken)->toBe(200)
        ->and(app(RestoreMode::class)->active())->toBeFalse();
});

test('dopo l\'import, chi nel database ripristinato ha l\'id di chi ha avviato il ripristino non apre il recupero con la sua password', function () {
    rdmInstallazione('file');
    // Nel backup l'account con quell'id è di un'altra persona, con la sua password.
    $admin = rdmAmministratore('password-di-bianchi');
    [$token, $pos] = rdmRipristinoAvviato($admin);
    // All'avvio lo stesso account è di chi avvia, con la sua password: lo stato ne salva l'impronta.
    $admin->forceFill(['password' => bcrypt('la-mia-password')])->save();
    app(RestoreState::class)->merge(['created_by_password_hash' => $admin->fresh()->getAuthPassword()]);
    rdmImportatore(PHP_INT_MAX);
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andThrow(new RuntimeException('Errore della finalizzazione')));

    expect(rdmGuida($this, $token))->toBe(['200 failed'])
        ->and(Hash::check('password-di-bianchi', User::find($admin->id)->getAuthPassword()))->toBeTrue();

    rdmNuovaRichiesta();
    $altraPersona = $this->postJson('/ripristino/annulla', ['account_password' => 'password-di-bianchi']);
    rdmNuovaRichiesta();
    $chiHaAvviato = $this->postJson('/ripristino/annulla', ['account_password' => 'la-mia-password']);

    expect($altraPersona->status())->toBe(422)
        ->and($chiHaAvviato->status())->toBe(200)
        ->and(app(RestoreMode::class)->active())->toBeFalse();
});

test('uno stato con la chiave dell\'impronta vuota non ripiega sulla tabella degli utenti: la password non si può verificare, la risposta lo dice, e la via è il token', function () {
    // Lo stato di questa versione ha sempre la chiave: con l'impronta, o vuota. La tabella degli utenti si legge solo
    // per gli stati scritti prima, che la chiave non l'hanno (i test qui sotto), anche se lì c'è l'account con quella
    // password.
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    [$token] = rdmRipristinoAvviato($admin);
    app(RestoreState::class)->merge(['created_by_password_hash' => null, 'phase' => 'failed', 'failed_phase' => 'finalizing', 'failed_at' => time()]);
    expect(Hash::check('la-mia-password', User::find($admin->id)->getAuthPassword()))->toBeTrue();

    rdmNuovaRichiesta();
    $conLaPassword = $this->postJson('/ripristino/annulla', ['account_password' => 'la-mia-password']);
    rdmNuovaRichiesta();
    $conIlToken = $this->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token]);

    expect($conLaPassword->status())->toBe(409)
        ->and($conLaPassword->json('message'))->toBe(trans_choice('impostazioni.restore_recovery.auth_unavailable', (int) config('backup.stale_after_hours'), ['ore' => (int) config('backup.stale_after_hours')]))
        ->and($conIlToken->status())->toBe(200)
        ->and(app(RestoreMode::class)->active())->toBeFalse();
});

// Un ripristino avviato con una versione precedente e rimasto aperto durante l'aggiornamento ha lo stato senza la chiave
// dell'impronta. Per quello la password di chi l'ha avviato si cerca nella tabella degli utenti; se la
// tabella non si legge o non ha ancora l'account (l'import a metà), la password non si può verificare: la risposta lo
// dice invece di «Password non corretta.», e resta il token.

test('per un ripristino avviato prima dell\'aggiornamento (lo stato senza la chiave dell\'impronta), dalla 503 la password giusta di chi l\'ha avviato apre il recupero, quella sbagliata no', function (string $azione) {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRipristinoAvviato($admin);
    rdmStatoDiPrima(['phase' => 'failed', 'failed_phase' => 'finalizing', 'failed_at' => time()]);
    expect(app(RestoreState::class)->get())->not->toHaveKey('created_by_password_hash');
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andReturnNull());

    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    rdmNuovaRichiesta();
    $sbagliata = $this->postJson("/ripristino/{$azione}", ['account_password' => 'sbagliata']);
    rdmNuovaRichiesta();
    $giusta = $this->postJson("/ripristino/{$azione}", ['account_password' => 'la-mia-password']);

    expect($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($sbagliata->status())->toBe(422)
        ->and($sbagliata->json('errors.account_password.0'))->toBe(__('impostazioni.restore_recovery.auth_failed'))
        ->and($giusta->status())->toBe(200, (string) ($giusta->json('errors.account_password.0') ?? $giusta->json('message')))
        ->and(app(RestoreMode::class)->active())->toBeFalse();
})->with(['riprendi', 'annulla']);

test('per un ripristino avviato prima dell\'aggiornamento, con l\'import fermo prima che la tabella degli utenti abbia i dati, la password non si può verificare: la risposta lo dice con le ore del token, senza errori del server; fallito entro la durata del token, la finestra dei backup lo riprende con il suo token', function (string $punto) {
    // Le ore della frase vengono da `stale_after_hours`, che è anche la durata del token della finestra.
    config(['backup.stale_after_hours' => 3]);
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    [$backup, $pos] = rdmArchivioNelDisco();
    rdmImportatore($punto === 'la tabella non c\'è ancora' ? $pos['crea_users'] - 3 : $pos['crea_users'] + 2);
    $token = rdmAvviaDallaFinestra($this, $admin, $backup)->json('token');
    expect(rdmGuida($this, $token, 1))->toBe(['200 importing_database'])
        ->and(DB::getSchemaBuilder()->hasTable('users'))->toBe($punto !== 'la tabella non c\'è ancora');
    // Il ripristino si ferma adesso: lo stato è fallito entro la durata del token.
    rdmStatoDiPrima(['phase' => 'failed', 'failed_phase' => 'importing_database', 'failed_at' => time()]);
    PdoDelRipristinoDiProva::$perPasso = PHP_INT_MAX;

    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    $risposte = [];
    foreach (['riprendi', 'annulla'] as $azione) {
        rdmNuovaRichiesta();
        $r = $this->postJson("/ripristino/{$azione}", ['account_password' => 'la-mia-password']);
        $risposte[] = [$r->status(), $r->json('message')];
    }
    $dallAvvio = time() - app(RestoreState::class)->get()['started_at'];
    rdmNuovaRichiesta();
    $conIlToken = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);
    $dopo = $conIlToken->json('restore.phase') === 'completed' ? ['200 completed'] : rdmGuida($this, (string) $conIlToken->json('token'));

    // La frase promette la finestra solo se dall'avvio non sono passate più delle ore del token: qui è così, e la
    // finestra, con il suo token, riprende il ripristino.
    $nonVerificabile = [409, trans_choice('impostazioni.restore_recovery.auth_unavailable', 3, ['ore' => 3])];
    expect($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($risposte)->toBe([$nonVerificabile, $nonVerificabile])
        ->and($risposte[0][1])->toContain('più di 3 ore')
        ->and($dallAvvio)->toBeLessThanOrEqual(3 * 3600)
        ->and($conIlToken->status())->toBe(200)
        ->and(end($dopo))->toBe('200 completed');
})->with(['la tabella non c\'è ancora', 'la tabella c\'è ed è vuota']);

test('per un ripristino avviato prima dell\'aggiornamento e fermo da più della durata del token (avvio, token e ultimo passo spostati insieme), la password non si può verificare e la risposta non promette la finestra dei backup, il cui token non passa più', function () {
    config(['backup.stale_after_hours' => 3]);
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    [$backup, $pos] = rdmArchivioNelDisco();
    rdmImportatore($pos['crea_users'] - 3);
    $token = rdmAvviaDallaFinestra($this, $admin, $backup)->json('token');
    expect(rdmGuida($this, $token, 1))->toBe(['200 importing_database']);
    rdmStatoDiPrima();
    rdmTempiIndietro(3 * 3600 + 60);
    PdoDelRipristinoDiProva::$perPasso = PHP_INT_MAX;

    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    rdmNuovaRichiesta();
    $password = $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password']);
    // La finestra dei backup ancora aperta: lo step, «Riprendi» e «Sblocca» mandano solo il suo token.
    rdmNuovaRichiesta();
    $finestraStep = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $token]);
    rdmNuovaRichiesta();
    $finestraRiprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);
    rdmNuovaRichiesta();
    $finestraSblocca = $this->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token]);

    // La frase lega la finestra alle ore del token dall'avvio: qui sono passate, e la finestra infatti non può.
    expect($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and([$password->status(), $password->json('message')])->toBe([409, trans_choice('impostazioni.restore_recovery.auth_unavailable', 3, ['ore' => 3])])
        ->and($password->json('message'))->toContain('più di 3 ore')
        ->and(time() - app(RestoreState::class)->get()['started_at'])->toBeGreaterThan(3 * 3600)
        ->and($finestraStep->status())->toBe(403)
        ->and($finestraRiprendi->status())->toBe(409)
        ->and($finestraSblocca->status())->toBe(409)
        ->and(app(RestoreMode::class)->active())->toBeTrue();
});

test('la frase della password che non si può verificare dice le ore del token, al singolare con un\'ora', function () {
    config(['backup.stale_after_hours' => 1]);
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRipristinoAvviato($admin);
    rdmStatoDiPrima(['phase' => 'failed', 'failed_phase' => 'importing_database', 'failed_at' => time()]);
    DB::table('users')->delete();

    rdmNuovaRichiesta();
    $r = $this->postJson('/ripristino/annulla', ['account_password' => 'la-mia-password']);

    expect($r->status())->toBe(409)
        ->and($r->json('message'))->toContain('più di un\'ora')
        ->and($r->json('message'))->not->toContain('1 ore');
});

test('la 503 di recupero scrive la frase della password che non si può verificare con le ore del token, nella lingua dell\'installazione, come la risposta del recupero', function (string $lingua, int $ore, string $frammento) {
    config(['backup.stale_after_hours' => $ore]);
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRipristinoAvviato($admin);
    rdmStatoDiPrima(['phase' => 'failed', 'failed_phase' => 'importing_database', 'failed_at' => time(), 'locale' => $lingua]);
    app(RestoreMode::class)->enter(app(RestoreState::class)->get()['uuid'], $lingua);

    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    // I testi della pagina stanno nello script, in JSON.
    preg_match('/^\s*var T = (.*);$/m', (string) $pagina->getContent(), $trovato);
    $frase = json_decode($trovato[1] ?? 'null', true)['auth_unavailable'] ?? null;

    expect($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($frase)->toContain($frammento)
        ->and($frase)->toBe(trans_choice('impostazioni.restore_recovery.auth_unavailable', $ore, ['ore' => $ore], $lingua));
})->with([
    ['it', 3, 'più di 3 ore'], ['en', 3, 'more than 3 hours'], ['pt', 3, 'mais de 3 horas'], ['es', 3, 'más de 3 horas'],
    ['it', 1, 'più di un\'ora'], ['en', 1, 'more than an hour'], ['pt', 1, 'mais de uma hora'], ['es', 1, 'más de una hora'],
]);

test('anche quando la password non si può verificare il tentativo conta: cinque prove al minuto, la sesta si ferma; il token passa', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    [$token] = rdmRipristinoAvviato($admin);
    app(RestoreState::class)->merge(['created_by_password_hash' => null, 'phase' => 'failed', 'failed_phase' => 'finalizing', 'failed_at' => time()]);

    $esiti = [];
    for ($i = 0; $i < 6; $i++) {
        rdmNuovaRichiesta();
        $esiti[] = $this->postJson('/ripristino/annulla', ['account_password' => 'prova-'.$i])->status();
    }
    rdmNuovaRichiesta();
    $conIlToken = $this->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token]);

    expect($esiti)->toBe([409, 409, 409, 409, 409, 429])
        ->and($conIlToken->status())->toBe(200);
});

test('l\'impronta della password di chi avvia sta nello stato solo mentre il ripristino è aperto, e nessuna risposta la porta', function (string $chiusura) {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    Storage::fake('backups');
    config(['filesystems.disks.backups.root' => Storage::disk('backups')->path('')]);
    $dump = rdmScriviIlDump(rdmCartella().'/database.sql');
    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path('archivio.zip'), ZipArchive::CREATE);
    $zip->addFromString('manifest.json', json_encode(['manifest_format' => 1, 'contents' => 'db_only', 'encrypted' => false,
        'app' => ['version' => config('app.version')], 'database' => ['driver' => 'sqlite', 'dump_sha256' => hash_file('sha256', $dump)]]));
    $zip->addFile($dump, 'db/database.sql');
    $zip->close();
    $backup = Backup::create(['uuid' => (string) Str::uuid(), 'filename' => 'archivio.zip', 'disk' => 'backups',
        'status' => \App\Enums\BackupStatus::COMPLETED, 'type' => 'db_only']);
    rdmImportatore(PHP_INT_MAX);
    if ($chiusura === 'annullato') {
        $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andThrow(new RuntimeException('Errore della finalizzazione')));
    }
    $impronta = $admin->getAuthPassword();

    $avvio = $this->actingAs($admin)->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
        'account_password' => 'la-mia-password', 'password' => null, 'safety_backup' => false, 'adopt_app_key' => false,
    ])->assertOk();
    $nelloStato = app(RestoreState::class)->get()['created_by_password_hash'] ?? null;

    rdmNuovaRichiesta();
    $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $avvio->json('token')]);
    rdmNuovaRichiesta();
    $stato = $this->getJson('/ripristino/stato', ['X-Restore-Token' => $avvio->json('token')]);
    if ($chiusura === 'annullato') {
        expect($passo->json('restore.phase'))->toBe('failed');
        rdmNuovaRichiesta();
        $this->postJson('/ripristino/annulla', ['account_password' => 'la-mia-password'])->assertOk();
    }

    $file = File::get(config('backup.restore.state_file'));

    expect($nelloStato)->toBe($impronta)
        ->and($passo->json('restore.phase'))->toBe($chiusura === 'completato' ? 'completed' : 'failed')
        ->and($avvio->getContent().$passo->getContent().$stato->getContent())->not->toContain($impronta)
        ->and($file)->not->toContain($impronta)
        ->and(json_decode($file, true)['created_by_password_hash'] ?? null)->toBeNull();
})->with(['completato', 'annullato']);

// --- «Riprendi» dopo un errore a metà di un passo dei dati --------------------------------------------------------
// Le istruzioni del dump si scrivono una per una, fuori da una transazione: il punto di ripresa si salva anche
// sull'errore, all'istruzione che non è riuscita, e «Riprendi» riparte da lì invece di rieseguire quelle già fatte.

test('un errore a metà di un passo dei dati: «Riprendi» con il token riparte dall\'istruzione che non è riuscita e arriva in fondo', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(400);
    [$token, $pos] = rdmRipristinoAvviato($admin);
    $perPasso = $pos['primo_dato'] + 20;
    rdmImportatore($perPasso);
    PdoDelRipristinoDiProva::$guastoAllIstruzione = $perPasso + 50;

    $prima = rdmGuida($this, $token);
    $errore = rdmErrore();
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);
    $dopo = $riprendi->json('restore.phase') === 'failed' ? ['200 failed: '.rdmErrore()] : rdmGuida($this, $riprendi->json('token'));

    expect($prima)->toBe(['200 importing_database', '200 failed'])
        ->and($errore)->toContain('Errore passeggero del database')
        ->and($riprendi->status())->toBe(200)
        ->and(end($dopo))->toBe('200 completed')
        ->and(DB::table('aa_righe')->count())->toBe(400)
        ->and(DB::table('aa_righe')->max('id'))->toBe(400);
});

test('un errore nel passo del wipe, prima dei dati: «Riprendi» riparte e arriva in fondo', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(100);
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore(PHP_INT_MAX);
    PdoDelRipristinoDiProva::$guastoAllIstruzione = $pos['primo_dato'] - 30;

    $prima = rdmGuida($this, $token);
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);

    expect($prima)->toBe(['200 failed'])
        ->and($riprendi->json('restore.phase'))->toBe('completed')
        ->and(DB::table('aa_righe')->count())->toBe(100);
});

// --- I percorsi del server nei messaggi del ripristino ------------------------------------------------------------
// L'errore di un passo arriva alla finestra dei backup (lo step, lo stato) e, dopo «Riprendi», al log della pagina 503:
// senza i percorsi del server. Il percorso resta nel log del server, per l'assistenza.

test('il dump estratto che non c\'è più o non si legge quando parte l\'import: lo step lo dice senza il percorso, che resta nel log del server', function (string $caso) {
    rdmInstallazione('file');
    $log = rdmLogDelServer();
    $admin = rdmAmministratore();
    [$token] = rdmRipristinoAvviato($admin);
    rdmImportatore(PHP_INT_MAX);
    $dump = rdmCartellaDelRipristino().'/extracted/db/database.sql';
    $caso === 'non c\'è più' ? unlink($dump) : chmod($dump, 0000);

    try {
        $risposte = rdmGuida($this, $token);
    } finally {
        @chmod($dump, 0644);
        File::deleteDirectory(rdmCartellaDelRipristino());
    }

    // Nel log del server il percorso e, se il sistema l'ha data, la causa.
    expect($risposte)->toBe(['200 failed'])
        ->and(app(RestoreState::class)->get()['error'])->toBe(__('impostazioni.restore_engine.dump_unreadable'))
        ->and(File::get($log))->toContain($dump)
        ->and(str_contains(File::get($log), 'Permission denied'))->toBe($caso === 'non si legge');
})->with(['non c\'è più', 'non si legge']);

test('un errore del sistema che porta un percorso del server arriva alla finestra senza il percorso, che resta nel log del server', function (string $caso) {
    rdmInstallazione('file');
    $log = rdmLogDelServer();
    $admin = rdmAmministratore();
    // I temporanei del ripristino e gli archivi si possono spostare fuori dalla cartella dell'installazione
    // (`BACKUP_TMP_PATH`, `BACKUP_ROOT`).
    $fuori = sys_get_temp_dir().'/kondo-ripristino-'.Str::lower(Str::random(8));
    if ($caso === 'il .env dell\'archivio, con i temporanei fuori dall\'installazione') {
        config(['backup.tmp_path' => $fuori]);
    }
    if ($caso === 'un archivio, con la cartella dei backup fuori dall\'installazione') {
        config(['filesystems.disks.backups.root' => $fuori]);
    }
    [$token] = rdmRipristinoAvviato($admin);
    rdmImportatore(PHP_INT_MAX);
    app(RestoreState::class)->merge(['phase' => 'finalizing']);
    $env = rdmCartellaDelRipristino().'/extracted/files/.env';

    if ($caso === 'il .env dell\'archivio, con i temporanei fuori dall\'installazione') {
        // L'adozione della chiave, e il .env dell'archivio che non si legge: l'errore è l'avviso di PHP, con il percorso
        // del file. Il .env non ha la riga della chiave: l'adozione non scriverebbe comunque niente.
        $percorso = $env;
        File::ensureDirectoryExists(dirname($env));
        File::put($env, "NOME=prova\n");
        chmod($env, 0000);
        app(RestoreState::class)->merge(['options' => ['safety_backup' => false, 'adopt_app_key' => true]]);
    } else {
        // La finalizzazione si ferma con l'avviso di PHP su un file: un file dell'installazione o un archivio.
        $percorso = $caso === 'un file dell\'installazione' ? base_path('database/migrations/2026_01_01_000000_prova.php') : $fuori.'/kondomanager-backup-prova.zip';
        $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')
            ->andThrow(new ErrorException("hash_file({$percorso}): Failed to open stream: Permission denied")));
    }

    try {
        rdmNuovaRichiesta();
        $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $token]);
        rdmNuovaRichiesta();
        $stato = $this->getJson('/ripristino/stato', ['X-Restore-Token' => $token]);
    } finally {
        @chmod($env, 0644);
        File::deleteDirectory(rdmCartellaDelRipristino());
        File::deleteDirectory($fuori);
    }

    $errore = (string) $passo->json('restore.error');
    expect($passo->json('restore.phase'))->toBe('failed')
        ->and($errore)->toContain(basename($percorso))
        ->and($errore)->not->toContain(dirname($percorso))
        ->and($errore)->not->toMatch('#(^|[\s(\'":])/\w#')
        ->and($stato->json('restore.error'))->toBe($errore)
        ->and(File::get($log))->toContain($percorso);
})->with([
    'il .env dell\'archivio, con i temporanei fuori dall\'installazione',
    'un file dell\'installazione',
    'un archivio, con la cartella dei backup fuori dall\'installazione',
]);

test('nell\'immagine Docker, con l\'installazione in /app, la rete toglie le cartelle del server solo dove aprono un percorso: dentro un percorso o nei dati di un errore restano', function () {
    // La cartella si toglie dove apre un percorso (prima: l'inizio, uno spazio, una parentesi, le virgolette, una
    // virgola, «=», «:» o «;»; dopo: «/», la fine del percorso o il punto che chiude la frase), le cartelle più lunghe
    // prima.
    $casi = [
        'fopen(/app/storage/app/private/documenti/verbale.pdf): Failed to open stream: Permission denied' => 'fopen(…/storage/app/private/documenti/verbale.pdf): Failed to open stream: Permission denied',
        'rename(/app/storage/app/public/x.jpg,/app/storage/app/backups/tmp/restore-1/old/public/x.jpg): Permission denied' => 'rename(…/storage/app/public/x.jpg,…/restore-1/old/public/x.jpg): Permission denied',
        "SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: insert into `impostazioni` values ('https://studio.example/app/login'))" => "SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: insert into `impostazioni` values ('https://studio.example/app/login'))",
        'copy(/app/storage/app/backups/kondomanager-backup-prova.zip): Failed to open stream: Permission denied' => 'copy(…/kondomanager-backup-prova.zip): Failed to open stream: Permission denied',
        'mkdir(/app/storage/app/backups/tmp): Permission denied' => 'mkdir(…): Permission denied',
        '/app/storage/logs/laravel.log non si scrive' => '…/storage/logs/laravel.log non si scrive',
        "file '/app/.env' illeggibile, percorso=/app/bootstrap/cache e cartella: /app" => "file '…/.env' illeggibile, percorso=…/bootstrap/cache e cartella: …",
        'opendir(/application/x): Failed to open directory' => 'opendir(/application/x): Failed to open directory',
        // La cartella che chiude la frase, seguita dal punto: si toglie intera. Un punto dentro un nome resta.
        'Unable to create a directory at /app/storage/app/backups. mkdir(): Permission denied' => 'Unable to create a directory at …. mkdir(): Permission denied',
        'Unable to create a directory at /app/storage/app/backups/tmp.' => 'Unable to create a directory at ….',
        'rename(/app,/app.old): Permission denied' => 'rename(…,/app.old): Permission denied',
        // Due percorsi separati dal punto e virgola: si toglie la cartella di tutti e due.
        'scrittura in /app/storage/app/backups/tmp;/app/storage/app/backups/x.zip' => 'scrittura in …;…/x.zip',
    ];
    $prima = base_path();
    app()->setBasePath('/app');
    config(['backup.tmp_path' => '/app/storage/app/backups/tmp', 'filesystems.disks.backups.root' => '/app/storage/app/backups']);

    try {
        $esiti = array_map(fn (string $messaggio) => MessaggiDelRipristino::senzaPercorsi($messaggio), array_keys($casi));
    } finally {
        app()->setBasePath($prima);
    }

    expect($esiti)->toBe(array_values($casi));
});

test('se l\'espressione della rete non si applica, la rete toglie ogni occorrenza delle cartelle: nessun percorso del server esce', function () {
    $prima = [ini_get('pcre.backtrack_limit'), ini_get('pcre.jit')];
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $esito = MessaggiDelRipristino::senzaPercorsi('fopen('.base_path('storage/app/x.pdf').'): Permission denied');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $prima[0]);
        ini_set('pcre.jit', (string) $prima[1]);
    }

    expect($esito)->toBe('fopen(…/storage/app/x.pdf): Permission denied');
});

// --- Il token della finestra con il tempo che passa ---------------------------------------------------------------
// Il token della finestra dei backup vale `stale_after_hours` dall'ultimo passo del ripristino, non dall'avvio: un
// ripristino che avanza non si ferma per il token. Uno che resta fermo per lo stesso tempo lo perde, e lì la 503 offre
// il recupero con la password. Il tempo che passa si fa spostando indietro insieme tutti i tempi dello stato.

test('un ripristino che fa ancora passi due ore e un minuto dopo l\'avvio va avanti: il token della finestra vale dall\'ultimo passo, e la 503 resta quella dell\'attesa', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(60);
    [$backup] = rdmArchivioNelDisco();
    rdmImportatore(40);
    $token = rdmAvviaDallaFinestra($this, $admin, $backup)->json('token');

    expect(rdmGuida($this, $token, 1))->toBe(['200 importing_database']);
    rdmTempiIndietro(3600);
    expect(rdmGuida($this, $token, 1))->toBe(['200 importing_database']);
    // Due ore e un minuto dall'avvio, un'ora e un minuto dall'ultimo passo.
    rdmTempiIndietro(3660);
    $dallAvvio = time() - app(RestoreState::class)->get()['started_at'];
    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    $passo = rdmGuida($this, $token, 1);
    $dopo = rdmGuida($this, $token);

    expect($dallAvvio)->toBeGreaterThan(2 * 3600)
        ->and($pagina->status())->toBe(503)
        ->and($pagina->getContent())->not->toContain('id="resumeBtn"')
        ->and($passo)->toBe(['200 importing_database'])
        ->and(end($dopo))->toBe('200 completed')
        ->and(DB::table('aa_righe')->count())->toBe(60);
});

test('un ripristino senza passi per due ore e un minuto: il token della finestra non passa più, la 503 offre il recupero, e con la password di chi l\'ha avviato si riprende', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(60);
    [$backup] = rdmArchivioNelDisco();
    rdmImportatore(40);
    $token = rdmAvviaDallaFinestra($this, $admin, $backup)->json('token');

    expect(rdmGuida($this, $token, 1))->toBe(['200 importing_database']);
    rdmTempiIndietro(2 * 3600 + 60);
    rdmNuovaRichiesta();
    $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $token]);
    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password']);
    PdoDelRipristinoDiProva::$perPasso = PHP_INT_MAX;
    $dopo = $riprendi->json('restore.phase') === 'completed' ? ['200 completed'] : rdmGuida($this, (string) $riprendi->json('token'));

    expect($passo->status())->toBe(403)
        ->and($passo->json('message'))->toBe('Token di ripristino mancante, errato o scaduto.')
        ->and($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($riprendi->status())->toBe(200)
        ->and(end($dopo))->toBe('200 completed')
        ->and(DB::table('aa_righe')->count())->toBe(60);
});

test('un ripristino che si ferma su un errore un\'ora e cinquantanove minuti dopo l\'avvio: un\'ora dopo, «Riprendi» della finestra con il suo token lo riprende ancora', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(60);
    [$backup] = rdmArchivioNelDisco();
    rdmImportatore(40);
    $token = rdmAvviaDallaFinestra($this, $admin, $backup)->json('token');

    expect(rdmGuida($this, $token, 1))->toBe(['200 importing_database']);
    rdmTempiIndietro(2 * 3600 - 60);
    PdoDelRipristinoDiProva::$guastoAllIstruzione = PdoDelRipristinoDiProva::$eseguite + 5;
    expect(rdmGuida($this, $token, 1))->toBe(['200 failed']);
    // Un'ora dopo il passo che si è fermato: due ore e cinquantanove minuti dall'avvio.
    rdmTempiIndietro(3600);
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);
    PdoDelRipristinoDiProva::$perPasso = PHP_INT_MAX;
    $dopo = $riprendi->json('restore.phase') === 'completed' ? ['200 completed'] : rdmGuida($this, (string) $riprendi->json('token'));

    expect($riprendi->status())->toBe(200)
        ->and(end($dopo))->toBe('200 completed')
        ->and(DB::table('aa_righe')->count())->toBe(60);
});

// --- Il backup di sicurezza ---------------------------------------------------------------------------------------
// Con la casella del backup di sicurezza spuntata (di serie) il ripristino tocca il database solo dopo un backup di
// sicurezza riuscito: è la via di ritorno che la casella promette.

/** Il backup di sicurezza che non riesce: il dump del database si ferma con un errore. */
function rdmDumpCheNonRiesce(): void
{
    app()->bind(DatabaseDumperInterface::class, fn () => new class implements DatabaseDumperInterface
    {
        public function dumpFilename(): string
        {
            return 'database.sqlite';
        }

        public function dump(string $targetPath, array &$state, StepBudget $budget): bool
        {
            throw new RuntimeException('Spazio su disco esaurito durante il dump.');
        }
    });
}

test('con il backup di sicurezza che non riesce, il ripristino si ferma prima di toccare il database: le righe scritte dopo il backup restano, e la finestra dice perché', function () {
    rdmInstallazione('file');
    $log = rdmLogDelServer();
    $admin = rdmAmministratore();
    rdmRiempi(30);
    [$backup] = rdmArchivioNelDisco();
    // Una riga scritta dopo il backup: con il database ripristinato non ci sarebbe più.
    DB::table('aa_righe')->insert(['id' => 31, 'valore' => 'scritta dopo il backup']);
    rdmImportatore(PHP_INT_MAX);
    rdmDumpCheNonRiesce();

    $token = rdmAvviaDallaFinestra($this, $admin, $backup, true)->json('token');
    $risposte = rdmGuida($this, $token);
    rdmNuovaRichiesta();
    $stato = $this->getJson('/ripristino/stato', ['X-Restore-Token' => $token]);
    $sicurezza = Backup::where('uuid', '!=', $backup->uuid)->get();

    // Nel log del server la causa (la riga del backup) e, sulla riga del ripristino, quale backup non è riuscito.
    expect($risposte)->toBe(['200 failed'])
        ->and($stato->json('restore.failed_phase'))->toBe('safety_backup')
        ->and($stato->json('restore.error'))->toBe(__('impostazioni.restore_engine.safety_backup_failed', ['casella' => __('impostazioni.label.restore_safety_backup')]))
        ->and($sicurezza->map(fn (Backup $b) => $b->status->value)->all())->toBe(['failed'])
        ->and(DB::table('aa_righe')->count())->toBe(31)
        ->and(app(RestoreState::class)->get()['checkpoint'])->not->toHaveKey('wiped')
        ->and(app(RestoreMode::class)->active())->toBeTrue()
        ->and(File::get($log))->toContain('Spazio su disco esaurito durante il dump.')
        ->and(File::get($log))->toContain('"backup":"'.$sicurezza->first()->uuid.'"');
});

test('«Riprendi» dopo il backup di sicurezza non riuscito rifà il backup di sicurezza: riuscito, il ripristino va in fondo', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(30);
    [$backup] = rdmArchivioNelDisco();
    DB::table('aa_righe')->insert(['id' => 31, 'valore' => 'scritta dopo il backup']);
    rdmImportatore(PHP_INT_MAX);
    rdmDumpCheNonRiesce();

    $token = rdmAvviaDallaFinestra($this, $admin, $backup, true)->json('token');
    $prima = rdmGuida($this, $token);
    // Il problema del disco è risolto: il dump vero del database dell'installazione.
    app()->bind(DatabaseDumperInterface::class, fn () => app(SqliteDumper::class));
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);
    $dopo = $riprendi->json('restore.phase') === 'completed' ? ['200 completed'] : rdmGuida($this, (string) $riprendi->json('token'));
    $archivi = array_map('basename', glob(rtrim((string) config('filesystems.disks.backups.root'), '/').'/*.zip') ?: []);

    expect($prima)->toBe(['200 failed'])
        ->and($riprendi->status())->toBe(200)
        ->and(end($dopo))->toBe('200 completed')
        ->and(DB::table('aa_righe')->count())->toBe(30)
        ->and($archivi)->toContain('archivio.zip')
        ->and(count($archivi))->toBe(2);
});

test('il backup di sicurezza che non parte, perché un altro backup risulta in corso, ferma il ripristino con la stessa frase, prima di toccare il database', function () {
    rdmInstallazione('file');
    $log = rdmLogDelServer();
    $admin = rdmAmministratore();
    rdmRiempi(30);
    [$backup] = rdmArchivioNelDisco();
    rdmImportatore(PHP_INT_MAX);
    $token = rdmAvviaDallaFinestra($this, $admin, $backup, true)->json('token');
    // Dopo l'avvio un altro backup risulta in corso: il backup di sicurezza non parte.
    Backup::create(['uuid' => (string) Str::uuid(), 'disk' => 'backups', 'status' => BackupStatus::DUMPING_DATABASE, 'type' => 'db_only']);

    $risposte = rdmGuida($this, $token);
    $stato = app(RestoreState::class)->get();

    expect($risposte)->toBe(['200 failed'])
        ->and($stato['failed_phase'])->toBe('safety_backup')
        ->and($stato['error'])->toBe(__('impostazioni.restore_engine.safety_backup_failed', ['casella' => __('impostazioni.label.restore_safety_backup')]))
        ->and(DB::table('aa_righe')->count())->toBe(30)
        ->and(File::get($log))->toContain('Un altro backup è già in esecuzione.');
});

/** Il testo che la 503 mostra, senza lo script: lo script porta tutte le frasi, per quando il ripristino si ferma di nuovo. */
function rdmTestoVisibile(string $html): string
{
    return html_entity_decode((string) preg_replace('#<script\b.*?</script>#s', '', $html), ENT_QUOTES);
}

test('dopo «Riprendi» dal backup di sicurezza non riuscito, lo stato in corso non porta più la fase del fallimento: le risposte la danno vuota, e la 503 di un ripristino fermo dopo la ripresa scrive nel log la fase in cui è, e che il database potrebbe essere incompleto', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(30);
    [$backup] = rdmArchivioNelDisco();
    rdmImportatore(40);
    rdmDumpCheNonRiesce();

    $token = rdmAvviaDallaFinestra($this, $admin, $backup, true)->json('token');
    $prima = rdmGuida($this, $token);
    // Il problema del disco è risolto: il backup di sicurezza riesce, e l'import parte.
    app()->bind(DatabaseDumperInterface::class, fn () => app(SqliteDumper::class));
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);
    rdmNuovaRichiesta();
    $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => (string) $riprendi->json('token')]);
    // La finestra si chiude a metà import: nessun passo per più della durata del token.
    rdmTempiIndietro(2 * 3600 + 60);
    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    $visibile = rdmTestoVisibile((string) $pagina->getContent());

    expect($prima)->toBe(['200 failed'])
        ->and($riprendi->status())->toBe(200)
        ->and($riprendi->json('restore.phase'))->toBe('importing_database')
        ->and($riprendi->json('restore.failed_phase'))->toBeNull()
        ->and($passo->json('restore.phase'))->toBe('importing_database')
        ->and($passo->json('restore.failed_phase'))->toBeNull()
        ->and(app(RestoreState::class)->get()['checkpoint']['wiped'] ?? false)->toBeTrue()
        ->and($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($visibile)->toContain('failed_phase: importing_database')
        ->and($visibile)->not->toContain('failed_phase: safety_backup')
        ->and($visibile)->toContain('Il database potrebbe trovarsi in uno stato incompleto')
        ->and($visibile)->toContain('Il database potrebbe restare incompleto.')
        ->and($visibile)->not->toContain('non ha toccato il database');
});

test('fermo nel backup di sicurezza, la 503 dice che il ripristino non ha toccato il database, nella frase sotto il titolo e nella nota sotto «Sblocca», nella lingua dell\'installazione', function (string $lingua, string $frase, string $nota, array $generiche) {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(30);
    [$backup] = rdmArchivioNelDisco();
    rdmImportatore(PHP_INT_MAX);
    rdmDumpCheNonRiesce();

    $token = rdmAvviaDallaFinestra($this, $admin, $backup, true)->json('token');
    $risposte = rdmGuida($this, $token);
    app(RestoreMode::class)->enter(app(RestoreState::class)->get()['uuid'], $lingua);
    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    $visibile = rdmTestoVisibile((string) $pagina->getContent());

    expect($risposte)->toBe(['200 failed'])
        ->and(DB::table('aa_righe')->count())->toBe(30)
        ->and($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($visibile)->toContain('failed_phase: safety_backup')
        ->and($visibile)->toContain($frase)
        ->and($visibile)->toContain($nota);
    foreach ($generiche as $generica) {
        expect($visibile)->not->toContain($generica);
    }
})->with([
    'it' => ['it', 'si è fermato durante il backup di sicurezza, prima di toccare il database', 'Il ripristino non ha toccato il database', ['potrebbe trovarsi in uno stato incompleto', 'potrebbe restare incompleto']],
    'en' => ['en', 'stopped during the safety backup, before touching the database', 'The restore did not touch the database', ['may be in an incomplete state', 'may stay incomplete']],
    'pt' => ['pt', 'parou durante a cópia de segurança prévia, antes de alterar a base de dados', 'O restauro não alterou a base de dados', ['pode ficar incompleta']],
    'es' => ['es', 'se detuvo durante la copia de seguridad previa, antes de modificar la base de datos', 'La restauración no modificó la base de datos', ['podría quedar incompleta']],
]);

test('dopo «Riprendi» da un errore nell\'import, la risposta e lo stato non portano più la fase del fallimento: se si ferma di nuovo, la scrive il fallimento nuovo', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    [$token, $pos] = rdmRipristinoAvviato($admin);
    rdmImportatore(PHP_INT_MAX);
    PdoDelRipristinoDiProva::$guastoAllIstruzione = $pos['primo_dato'] + 2;
    $prima = rdmGuida($this, $token, 1);
    $fermo = app(RestoreState::class)->get();
    // Un errore anche dopo la ripresa, più avanti nei dati.
    PdoDelRipristinoDiProva::$guastoAllIstruzione = $pos['primo_dato'] + 4;
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);

    expect($prima)->toBe(['200 failed'])
        ->and($fermo['failed_phase'])->toBe('importing_database')
        ->and($riprendi->status())->toBe(200)
        ->and($riprendi->json('restore.phase'))->toBe('failed')
        ->and($riprendi->json('restore.failed_phase'))->toBe('importing_database')
        ->and($riprendi->json('restore.error'))->toContain('Errore passeggero del database');

    // Ripreso senza errori: in corso, la fase del fallimento è vuota.
    PdoDelRipristinoDiProva::$perPasso = 1;
    rdmNuovaRichiesta();
    $ancora = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => (string) $riprendi->json('token')]);

    expect($ancora->status())->toBe(200)
        ->and($ancora->json('restore.phase'))->toBe('importing_database')
        ->and($ancora->json('restore.failed_phase'))->toBeNull()
        ->and(app(RestoreState::class)->get()['failed_phase'] ?? null)->toBeNull();
});

test('in stallo nel backup di sicurezza (la finestra chiusa mentre il backup di sicurezza è in corso), la 503 dice che il ripristino non ha toccato il database', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(30);
    [$backup] = rdmArchivioNelDisco();
    rdmAvviaDallaFinestra($this, $admin, $backup, true);
    // Lo stato come lo lascia un passo che ha avviato il backup di sicurezza, prima che finisca.
    app(RestoreState::class)->merge(['phase' => 'safety_backup']);
    rdmTempiIndietro(2 * 3600 + 60);

    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    $visibile = rdmTestoVisibile((string) $pagina->getContent());

    expect($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($visibile)->toContain('failed_phase: safety_backup')
        ->and($visibile)->toContain('si è fermato durante il backup di sicurezza, prima di toccare il database')
        ->and($visibile)->toContain('Il ripristino non ha toccato il database')
        ->and($visibile)->not->toContain('potrebbe trovarsi in uno stato incompleto');
});

test('uno stato in corso che porta ancora la fase di un fallimento di prima (ripreso con una versione precedente), fermo a metà import: la 503 dice la fase in cui è, e che il database potrebbe essere incompleto', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRipristinoAvviato($admin);
    // Fermo nel backup di sicurezza, ripreso con una versione che non azzerava la fase del fallimento, poi fermo nell'import.
    app(RestoreState::class)->merge(['phase' => 'importing_database', 'failed_phase' => 'safety_backup', 'resumed_at' => time(), 'checkpoint' => ['wiped' => true]]);
    rdmTempiIndietro(2 * 3600 + 60);

    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    $visibile = rdmTestoVisibile((string) $pagina->getContent());

    expect($pagina->status())->toBe(503)
        ->and($pagina->getContent())->toContain('id="resumeBtn"')
        ->and($visibile)->toContain('failed_phase: importing_database')
        ->and($visibile)->toContain('Il database potrebbe trovarsi in uno stato incompleto')
        ->and($visibile)->not->toContain('non ha toccato il database');
});

test('fermo nell\'import, la 503 dice ancora che il database potrebbe essere incompleto, sopra e sotto «Sblocca»', function () {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRipristinoAvviato($admin);
    app(RestoreState::class)->merge(['phase' => 'failed', 'failed_phase' => 'importing_database', 'failed_at' => time()]);

    rdmNuovaRichiesta();
    $pagina = $this->get('/');
    $visibile = rdmTestoVisibile((string) $pagina->getContent());

    expect($pagina->status())->toBe(503)
        ->and($visibile)->toContain('Il database potrebbe trovarsi in uno stato incompleto')
        ->and($visibile)->toContain('Il database potrebbe restare incompleto.')
        ->and($visibile)->not->toContain('non ha toccato il database');
});

// La finestra dei backup e la 503 dicono che il database non è stato toccato solo finché lo dice il server. Una sola
// richiesta di «Riprendi» rifà il backup di sicurezza, estrae, verifica e toglie il database: se la sua risposta non arriva,
// le pagine non possono contare su quella di prima. Un rifiuto di «Riprendi» (4xx) viene invece prima di ogni azione.

test('fermo nel backup di sicurezza, una sola richiesta di «Riprendi» arriva a togliere il database, anche quando risponde con un errore del server', function (string $caso) {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(30);
    [$backup] = rdmArchivioNelDisco();
    rdmImportatore(40);
    rdmDumpCheNonRiesce();

    $token = rdmAvviaDallaFinestra($this, $admin, $backup, true)->json('token');
    $prima = rdmGuida($this, $token);
    $fermo = app(RestoreState::class)->get();
    // Il problema del disco è risolto: il backup di sicurezza riesce, e l'import parte.
    app()->bind(DatabaseDumperInterface::class, fn () => app(SqliteDumper::class));
    if ($caso === 'errore del server') {
        // Il token nuovo non si scrive (il disco di nuovo pieno): la risposta è un errore, dopo che la ripresa ha lavorato.
        app()->bind(RestoreState::class, fn () => new class extends RestoreState
        {
            public function issueToken(int $ttlSeconds): string
            {
                throw new RuntimeException('Spazio su disco esaurito.');
            }
        });
    }
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token]);
    $dopo = app(RestoreState::class)->get();

    expect($prima)->toBe(['200 failed'])
        ->and($fermo['failed_phase'])->toBe('safety_backup')
        ->and($riprendi->status())->toBe($caso === 'errore del server' ? 500 : 200)
        ->and($dopo['phase'])->toBe('importing_database')
        ->and($dopo['failed_phase'] ?? null)->toBeNull()
        ->and($dopo['checkpoint']['wiped'] ?? false)->toBeTrue()
        ->and(app(RestoreMode::class)->active())->toBeTrue();
})->with(['la risposta che arriva', 'errore del server']);

test('fermo nel backup di sicurezza, «Riprendi» rifiutato (la password sbagliata, che non si può verificare, troppe prove, la modalità spenta) non tocca niente: il ripristino resta fermo dove era, con il database com\'era', function (string $caso, int $atteso) {
    rdmInstallazione('file');
    $admin = rdmAmministratore();
    rdmRiempi(30);
    [$backup] = rdmArchivioNelDisco();
    DB::table('aa_righe')->insert(['id' => 31, 'valore' => 'scritta dopo il backup']);
    rdmImportatore(PHP_INT_MAX);
    rdmDumpCheNonRiesce();

    $token = rdmAvviaDallaFinestra($this, $admin, $backup, true)->json('token');
    $prima = rdmGuida($this, $token);
    app()->bind(DatabaseDumperInterface::class, fn () => app(SqliteDumper::class));
    $password = 'la-mia-password';
    if ($caso === 'password sbagliata') {
        $password = 'sbagliata';
    } elseif ($caso === 'password che non si può verificare') {
        app(RestoreState::class)->merge(['created_by_password_hash' => null]);
    } elseif ($caso === 'troppe prove') {
        for ($i = 0; $i < 5; $i++) {
            rdmNuovaRichiesta();
            $this->postJson('/ripristino/riprendi', ['account_password' => 'sbagliata'])->assertStatus(422);
        }
    } else {
        app(RestoreMode::class)->exit();
    }
    rdmNuovaRichiesta();
    $riprendi = $this->postJson('/ripristino/riprendi', ['account_password' => $password]);
    $dopo = app(RestoreState::class)->get();

    expect($prima)->toBe(['200 failed'])
        ->and($riprendi->status())->toBe($atteso)
        ->and($dopo['phase'])->toBe('failed')
        ->and($dopo['failed_phase'])->toBe('safety_backup')
        ->and($dopo['checkpoint'])->not->toHaveKey('wiped')
        ->and(DB::table('aa_righe')->count())->toBe(31);
})->with([
    'password sbagliata' => ['password sbagliata', 422],
    'password che non si può verificare' => ['password che non si può verificare', 409],
    'troppe prove' => ['troppe prove', 429],
    'modalità spenta' => ['modalità spenta', 404],
]);

/** La cache nel database, come nell'immagine Docker: il limitatore si risolve all'avvio, va ricostruito. */
function rdmCacheNelDatabase(): void
{
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    app()->forgetInstance(\Illuminate\Cache\RateLimiter::class);
    \Illuminate\Support\Facades\Cache::clearResolvedInstances();
    \Illuminate\Support\Facades\RateLimiter::clearResolvedInstances();
}
