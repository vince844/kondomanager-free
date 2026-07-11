<?php

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\BackupManager;
use App\Services\Backup\Exceptions\BackupInProgressException;
use App\Services\Backup\FileSelector;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\File;
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
    File::deleteDirectory(storage_path('app/backups/tmp'));
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
    expect(File::exists(storage_path('app/backups/tmp/'.$backup->uuid)))->toBeFalse();
    expect(File::exists(storage_path('app/backups/tmp/'.$backup->uuid.'.zip')))->toBeFalse();
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
    $backup = $manager->start();

    Backup::where('id', $backup->id)->update(['updated_at' => now()->subHours(5)]);

    $manager->failStaleBackups();

    expect($backup->refresh()->status)->toBe(BackupStatus::FAILED);
    expect($backup->error)->not->toBeNull();

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
