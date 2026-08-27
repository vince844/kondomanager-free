<?php

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupPasswordStore;
use App\Services\Backup\Exceptions\BackupInProgressException;
use App\Services\Backup\FileSelector;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeBackupDumper;

/**
 * Test dello step-runner: macchina a stati, checkpoint, archivio zip,
 * manifest, retention e gestione dei backup stantii. Il dump del database
 * è simulato da un fake: la correttezza del dumper reale è coperta da
 * MySqlDumperRoundTripTest.
 */
function fixturePath(string $relative = ''): string
{
    return base_path('storage/framework/testing/backup-fixture'.($relative !== '' ? '/'.$relative : ''));
}

function makeManager(): BackupManager
{
    return app(BackupManager::class);
}

beforeEach(function () {
    Storage::fake('backups');

    $this->app->bind(DatabaseDumperInterface::class, fn () => new FakeBackupDumper);

    File::deleteDirectory(fixturePath());
    File::ensureDirectoryExists(fixturePath('documenti'));
    File::ensureDirectoryExists(fixturePath('escluso'));
    File::put(fixturePath('documenti/verbale.pdf'), str_repeat('PDF', 1000));
    File::put(fixturePath('documenti/.nascosto'), 'dotfile incluso');
    File::put(fixturePath('documenti/.DS_Store'), 'spazzatura macOS');
    File::put(fixturePath('escluso/segreto.txt'), 'non deve entrare nello zip');
    File::put(fixturePath('radice.txt'), 'file in radice');

    config()->set('backup.include', ['storage/framework/testing/backup-fixture']);
    config()->set('backup.exclude', ['storage/framework/testing/backup-fixture/escluso']);
});

afterEach(function () {
    File::deleteDirectory(fixturePath());
    File::deleteDirectory(config('backup.tmp_path'));
});

function runToCompletion(BackupManager $manager, Backup $backup, int $maxSteps = 10): Backup
{
    for ($i = 0; $i < $maxSteps && $backup->isRunning(); $i++) {
        $backup = $manager->runStep($backup);
    }

    return $backup->refresh();
}

test('un backup completo produce uno zip con dump, file, .env-like e manifest', function () {
    $manager = makeManager();
    $backup = $manager->start();

    expect($backup->status)->toBe(BackupStatus::PENDING);
    expect($backup->uuid)->not->toBeEmpty();

    $backup = runToCompletion($manager, $backup);

    expect($backup->status)->toBe(BackupStatus::COMPLETED);
    expect($backup->filename)->not->toBeNull();
    expect($backup->completed_at)->not->toBeNull();
    expect($backup->checkpoint)->toBeNull();
    expect($backup->progress['percent'])->toBe(100);

    Storage::disk('backups')->assertExists($backup->filename);

    $zipPath = Storage::disk('backups')->path($backup->filename);

    expect($backup->size)->toBe(filesize($zipPath));
    expect($backup->checksum)->toBe(hash_file('sha256', $zipPath));

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    expect($entries)->toContain('manifest.json');
    expect($entries)->toContain('db/database.sql');
    expect($entries)->toContain('files/storage/framework/testing/backup-fixture/documenti/verbale.pdf');
    expect($entries)->toContain('files/storage/framework/testing/backup-fixture/documenti/.nascosto');
    expect($entries)->toContain('files/storage/framework/testing/backup-fixture/radice.txt');
    expect(implode("\n", $entries))->not->toContain('escluso');
    expect(implode("\n", $entries))->not->toContain('.DS_Store');

    // Il contenuto dei file è integro
    expect($zip->getFromName('files/storage/framework/testing/backup-fixture/radice.txt'))->toBe('file in radice');
    expect($zip->getFromName('db/database.sql'))->toContain('dump di prova');

    // Il manifest è versionato e coerente, e coincide con la colonna in DB
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $zip->close();

    expect($manifest['manifest_format'])->toBe(1);
    expect($manifest['generator'])->toBe('kondomanager-free');
    expect($manifest['app']['version'])->toBe(config('app.version'));
    expect($manifest['database']['rows'])->toBe(1);
    expect($manifest['database']['dump_sha256'])->not->toBeNull();
    expect($manifest['migrations'])->not->toBeEmpty();
    expect($manifest['files']['count'])->toBe(3);
    expect($backup->manifest)->toEqual($manifest);

    // I temporanei sono stati ripuliti
    expect(File::exists(config('backup.tmp_path').'/'.$backup->uuid))->toBeFalse();
    expect(File::exists(config('backup.tmp_path').'/'.$backup->uuid.'.zip'))->toBeFalse();
});

test('non si possono avviare due backup contemporaneamente', function () {
    $manager = makeManager();
    $manager->start();

    expect(fn () => $manager->start())->toThrow(BackupInProgressException::class);
});

test('un backup interrotto riparte dal checkpoint e si completa', function () {
    $manager = makeManager();
    $backup = $manager->start();

    // Primo avanzamento parziale: solo il passaggio PENDING → DUMPING
    $backup->forceFill(['status' => BackupStatus::DUMPING_DATABASE])->save();

    // Simula la ripresa dopo un'interruzione (nuova richiesta HTTP)
    $resumed = Backup::where('uuid', $backup->uuid)->firstOrFail();
    $resumed = runToCompletion($manager, $resumed);

    expect($resumed->status)->toBe(BackupStatus::COMPLETED);
    Storage::disk('backups')->assertExists($resumed->filename);
});

test('la retention elimina i backup completati più vecchi del limite', function () {
    $settings = app(BackupSettings::class);
    $settings->retention_keep_last = 1;
    $settings->save();

    $manager = makeManager();

    $first = runToCompletion($manager, $manager->start());
    expect($first->status)->toBe(BackupStatus::COMPLETED);

    $this->travel(1)->minutes();

    $second = runToCompletion($manager, $manager->start());
    expect($second->status)->toBe(BackupStatus::COMPLETED);

    expect(Backup::where('status', BackupStatus::COMPLETED->value)->count())->toBe(1);
    expect(Backup::where('uuid', $first->uuid)->exists())->toBeFalse();
    Storage::disk('backups')->assertMissing($first->filename);
    Storage::disk('backups')->assertExists($second->filename);
});

test('i backup fermi da troppo tempo vengono marcati come falliti', function () {
    $manager = makeManager();
    $backup = $manager->start(null, ['password' => 'chiave-super-segreta']);

    Backup::where('id', $backup->id)->update(['updated_at' => now()->subHours(5)]);

    $manager->failStaleBackups();

    expect($backup->refresh()->status)->toBe(BackupStatus::FAILED);
    expect($backup->error)->not->toBeNull();
    // Il checkpoint (con la password cifrata) non deve sopravvivere
    // a un backup terminato, nemmeno se fallito
    expect($backup->checkpoint)->toBeNull();

    // Ora un nuovo backup può partire
    expect($manager->start())->toBeInstanceOf(Backup::class);
});

test('eliminare un backup rimuove archivio e record', function () {
    $manager = makeManager();
    $backup = runToCompletion($manager, $manager->start());

    $filename = $backup->filename;
    $manager->delete($backup);

    Storage::disk('backups')->assertMissing($filename);
    expect(Backup::where('uuid', $backup->uuid)->exists())->toBeFalse();
});

test('un backup "solo database" non contiene i file utente', function () {
    $manager = makeManager();
    $backup = runToCompletion($manager, $manager->start(null, ['type' => Backup::TYPE_DB_ONLY]));

    expect($backup->status)->toBe(BackupStatus::COMPLETED);
    expect($backup->type)->toBe(Backup::TYPE_DB_ONLY);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('backups')->path($backup->filename)))->toBeTrue();

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    $zip->close();

    // Solo dump e manifest: nessun file utente, nessun .env
    expect($entries)->toContain('db/database.sql');
    expect($entries)->toContain('manifest.json');
    expect(implode("\n", $entries))->not->toContain('files/');

    expect($backup->manifest['contents'])->toBe(Backup::TYPE_DB_ONLY);
    expect($backup->manifest['env_file'])->toBeNull();
    expect($backup->manifest['files']['count'])->toBe(0);
});

test('un backup cifrato è illeggibile senza password e leggibile con quella giusta', function () {
    $manager = makeManager();
    $backup = runToCompletion($manager, $manager->start(null, ['password' => 'chiave-super-segreta']));

    expect($backup->status)->toBe(BackupStatus::COMPLETED);
    expect($backup->encrypted)->toBeTrue();
    expect($backup->manifest['encrypted'])->toBeTrue();
    // La password (cifrata) viveva nel checkpoint, che al completamento sparisce
    expect($backup->checkpoint)->toBeNull();

    $zipPath = Storage::disk('backups')->path($backup->filename);

    // Senza password: l'elenco delle entry si vede, il contenuto no
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    expect($zip->getFromName('manifest.json'))->toBeFalse();
    expect($zip->getFromName('db/database.sql'))->toBeFalse();
    $zip->close();

    // Con la password sbagliata: ancora niente
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->setPassword('password-sbagliata');
    expect($zip->getFromName('manifest.json'))->toBeFalse();
    $zip->close();

    // Con la password giusta: tutto leggibile e integro
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->setPassword('chiave-super-segreta');
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    expect($manifest['manifest_format'])->toBe(1);
    expect($manifest['encrypted'])->toBeTrue();
    expect((string) $zip->getFromName('db/database.sql'))->toContain('dump di prova');
    expect((string) $zip->getFromName('files/storage/framework/testing/backup-fixture/radice.txt'))->toBe('file in radice');
    $zip->close();
});

test('un backup cifrato interrotto riprende e resta cifrato per intero', function () {
    $manager = makeManager();
    $backup = $manager->start(null, ['password' => 'chiave-super-segreta', 'type' => Backup::TYPE_FULL]);

    // Simula l'interruzione dopo il passaggio a DUMPING (nuova richiesta HTTP)
    $backup->forceFill(['status' => BackupStatus::DUMPING_DATABASE])->save();
    $resumed = runToCompletion($manager, Backup::where('uuid', $backup->uuid)->firstOrFail());

    expect($resumed->status)->toBe(BackupStatus::COMPLETED);
    expect($resumed->encrypted)->toBeTrue();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path($resumed->filename));

    // OGNI entry deve risultare cifrata AES-256 (nessuna in chiaro)
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        expect($stat['encryption_method'])->toBe(ZipArchive::EM_AES_256);
    }

    $zip->close();
});

test('la password salvata non finisce MAI dentro un backup', function () {
    // È la garanzia centrale della cifratura: se la password viaggiasse
    // dentro l'archivio, chiunque lo aprisse potrebbe leggerla e la
    // protezione degli archivi che lasciano il server sarebbe inutile.
    $store = app(BackupPasswordStore::class);
    $store->set('chiave-super-segreta');

    // Un backup NON cifrato è il caso peggiore: se contenesse la password
    // salvata, rivelerebbe la chiave di tutti gli archivi cifrati.
    $manager = makeManager();
    $backup = runToCompletion($manager, $manager->start());

    expect($backup->status)->toBe(BackupStatus::COMPLETED);

    $zipPath = Storage::disk('backups')->path($backup->filename);

    // Nessuna entry dell'archivio contiene la password né il file che la custodisce
    $zip = new ZipArchive;
    $zip->open($zipPath);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        expect($name)->not->toContain('.default-password');
        expect((string) $zip->getFromIndex($i))->not->toContain('chiave-super-segreta');
    }

    $zip->close();

    // Neanche nei byte grezzi dello zip (né in chiaro né come blob cifrato col nome noto)
    expect(file_get_contents($zipPath))->not->toContain('chiave-super-segreta');

    $store->clear();
});

test('il selettore dei file esclude i percorsi configurati e non segue i symlink', function () {
    // Un symlink dentro la fixture non va seguito né incluso
    symlink(fixturePath('documenti'), fixturePath('collegamento'));

    $collected = app(FileSelector::class)->collect();
    $paths = array_column($collected['files'], 0);

    expect($paths)->toContain('storage/framework/testing/backup-fixture/radice.txt');
    expect($paths)->toContain('storage/framework/testing/backup-fixture/documenti/verbale.pdf');
    expect(implode("\n", $paths))->not->toContain('escluso');
    expect(implode("\n", $paths))->not->toContain('collegamento');
    expect(implode("\n", $paths))->not->toContain('.DS_Store');
    expect($collected['total_bytes'])->toBeGreaterThan(0);
});

/**
 * `runningBackup()` miete i backup stantii prima di rispondere.
 *
 * ## Perché serve un test, e non basta il commento nel codice
 *
 * La Fase 1-bis della beta.2 ha rilevato che di otto correzioni questa era **l'unica senza un
 * test**: si poteva togliere la mietitura e la suite restava verde. E il rischio non è teorico —
 * chiunque legga un metodo che si chiama `runningBackup()` e ci trovi dentro una scrittura può
 * ragionevolmente decidere che un lettore non debba scrivere, e toglierla.
 *
 * Toglierla riporterebbe due difetti misurati:
 *
 * - `SystemUpgradeController::backupStart()` interroga questo metodo **prima** di `start()`, quindi
 *   riuserebbe un record morto invece di sostituirlo;
 * - `RestoreManager::start()` rifiuta di partire se questo metodo non torna `null`, quindi un
 *   backup abbandonato bloccherebbe **ogni ripristino** — proprio a chi ha appena visto fallire un
 *   aggiornamento e ha chiuso la scheda.
 *
 * ## Cosa questo test NON copre
 *
 * - **Non copre la finestra sotto le due ore.** Un backup interrotto da cinque minuti è
 *   considerato vivo, e per il percorso di backup è giusto: il chiamante lo riprende dal
 *   checkpoint. Che sia giusto anche per il *ripristino* è un'altra questione, ed è in coda.
 * - **Non prova che gli eventi non siano doppi.** `start()` chiama `failStaleBackups()` e poi
 *   questo metodo, che la richiama: la seconda passata trova la lista vuota perché i record non
 *   sono più `running`. È idempotenza per costruzione della query, non asserita qui.
 */
test('un backup abbandonato non conta piu come in corso, e non blocca chi arriva dopo', function () {
    $manager = app(BackupManager::class);

    $stantio = Backup::create([
        'uuid' => (string) Str::uuid(),
        'status' => BackupStatus::DUMPING_DATABASE,
        'type' => Backup::TYPE_DB_ONLY,
        'disk' => 'backups',
        'progress' => ['percent' => 40],
    ]);

    // Più vecchio della soglia dichiarata in config/backup.php.
    $ore = (int) config('backup.stale_after_hours', 2);
    $stantio->forceFill(['updated_at' => now()->subHours($ore + 1)])->saveQuietly();

    expect($manager->runningBackup())->toBeNull(
        'Un backup fermo da oltre la soglia viene ancora considerato in corso: bloccherebbe backup e ripristini.'
    );

    expect($stantio->fresh()->status)->toBe(BackupStatus::FAILED);
});

test('un backup interrotto da poco resta in corso, perche va ripreso dal checkpoint', function () {
    $manager = app(BackupManager::class);

    $recente = Backup::create([
        'uuid' => (string) Str::uuid(),
        'status' => BackupStatus::DUMPING_DATABASE,
        'type' => Backup::TYPE_DB_ONLY,
        'disk' => 'backups',
        'progress' => ['percent' => 40],
    ]);

    // La mietitura non deve essere troppo zelante: sotto la soglia il record va lasciato stare,
    // altrimenti una ripresa dopo un reload ricomincerebbe il dump da capo.
    expect($manager->runningBackup()?->id)->toBe($recente->id);
    expect($recente->fresh()->status)->toBe(BackupStatus::DUMPING_DATABASE);
});
