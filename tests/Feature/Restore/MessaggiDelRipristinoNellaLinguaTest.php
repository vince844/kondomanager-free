<?php

use App\Enums\BackupStatus;
use App\Enums\Permission;
use App\Models\Backup;
use App\Models\User;
use App\Services\Restore\RestoreLock;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;

/*
 * I messaggi del motore del ripristino che la finestra dei backup mostra all'avvio (un archivio che non si apre o
 * con la password sbagliata, un backup non compatibile, un ripristino o un backup già in corso) si leggono nella
 * lingua dell'installazione, come gli altri testi della finestra (decisione 134.2). Le richieste sono quelle della
 * finestra (`impostazioniBackups.vue`): JSON, con gli stessi campi.
 */

function mdrLingua(string $lingua): void
{
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->language = $lingua;
    $impostazioni->save();
}

function mdrAmministratore(): User
{
    $permesso = SpatiePermission::firstOrCreate(['name' => Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']);
    $utente = User::factory()->create(['password' => bcrypt('la-mia-password')]);
    $utente->givePermissionTo($permesso);

    return $utente;
}

/** Un archivio nella cartella dei backup, con le voci date (cifrate se c'è la password), e la sua riga. */
function mdrArchivio(array $voci, ?string $password = null, ?string $contenutoGrezzo = null): Backup
{
    $nome = 'kondomanager-backup-'.Str::lower(Str::random(6)).'.zip';
    $percorso = Storage::disk('backups')->path($nome);

    if ($contenutoGrezzo !== null) {
        file_put_contents($percorso, $contenutoGrezzo);
    } else {
        $zip = new ZipArchive;
        $zip->open($percorso, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($password !== null) {
            $zip->setPassword($password);
        }
        foreach ($voci as $voce => $contenuto) {
            $zip->addFromString($voce, $contenuto);
            if ($password !== null) {
                $zip->setEncryptionName($voce, ZipArchive::EM_AES_256);
            }
        }
        $zip->close();
    }

    return Backup::create([
        'uuid' => (string) Str::uuid(), 'filename' => $nome, 'disk' => 'backups',
        'status' => BackupStatus::COMPLETED, 'type' => 'db_only', 'encrypted' => $password !== null,
    ]);
}

function mdrVoci(string $versione): array
{
    return [
        'manifest.json' => json_encode(['manifest_format' => 1, 'contents' => 'db_only', 'encrypted' => false,
            'app' => ['version' => $versione], 'database' => ['driver' => config('database.connections.'.config('database.default').'.driver')]]),
        'db/database.sql' => "SET NAMES utf8mb4;\nSELECT 1;\n",
    ];
}

function mdrAvvia($test, User $utente, Backup $backup, ?string $passwordArchivio = null, bool $backupDiSicurezza = true)
{
    return $test->actingAs($utente)->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
        'account_password' => 'la-mia-password', 'password' => $passwordArchivio, 'safety_backup' => $backupDiSicurezza, 'adopt_app_key' => false,
    ]);
}

function mdrFrase(string $chiave, array $valori, string $lingua): string
{
    return __('impostazioni.restore_engine.'.$chiave, $valori, $lingua);
}

/** La frase c'è nella lingua (non è la chiave), e fuori dall'italiano non è la frase italiana. */
function mdrTradotta(string $chiave, array $valori, string $lingua): void
{
    expect(mdrFrase($chiave, $valori, $lingua))->not->toBe('impostazioni.restore_engine.'.$chiave);
    if ($lingua !== 'it') {
        expect(mdrFrase($chiave, $valori, $lingua))->not->toBe(mdrFrase($chiave, $valori, 'it'));
    }
}

/** Il log del server in un file della prova, per leggere che cosa ci finisce. */
function mdrLogDelServer(): string
{
    $file = storage_path('framework/testing/log-messaggi-ripristino-'.(ParallelTesting::token() ?: 'serial').'.log');
    @unlink($file);
    config(['logging.channels.ripristino_di_prova' => ['driver' => 'single', 'path' => $file, 'level' => 'debug'],
        'logging.default' => 'ripristino_di_prova']);

    return $file;
}

/** La risposta come la legge la finestra, senza le barre protette del JSON: un percorso si cerca così. */
function mdrComeLaLegge($risposta): string
{
    return json_encode($risposta->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

beforeEach(function () {
    Storage::fake('backups');
});

afterEach(function () {
    app(RestoreState::class)->clear();
    app(RestoreMode::class)->exit();
    @unlink(storage_path('framework/testing/log-messaggi-ripristino-'.(ParallelTesting::token() ?: 'serial').'.log'));
});

test('all\'avvio, la password sbagliata di un archivio cifrato si legge nella lingua dell\'installazione', function (string $lingua) {
    mdrLingua($lingua);
    $backup = mdrArchivio(mdrVoci(config('app.version')), 'password-giusta');

    mdrAvvia($this, mdrAmministratore(), $backup, 'password-sbagliata')
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', mdrFrase('archive_password', [], $lingua));

    mdrTradotta('archive_password', [], $lingua);
    expect(app(RestoreMode::class)->active())->toBeFalse();
})->with(['it', 'en', 'pt', 'es']);

test('all\'avvio, un backup di una versione più recente si legge nella lingua dell\'installazione', function (string $lingua) {
    mdrLingua($lingua);
    $backup = mdrArchivio(mdrVoci('99.0.0'));

    mdrAvvia($this, mdrAmministratore(), $backup)
        ->assertStatus(422)
        ->assertJsonPath('errors.archive.0', mdrFrase('newer_version', ['source' => '99.0.0', 'current' => config('app.version')], $lingua));

    mdrTradotta('newer_version', ['source' => '99.0.0', 'current' => config('app.version')], $lingua);
})->with(['it', 'en', 'pt', 'es']);

test('all\'avvio, un archivio che non si apre si legge nella lingua dell\'installazione, senza il percorso del file sul server', function (string $lingua) {
    mdrLingua($lingua);
    $backup = mdrArchivio([], null, 'questo non è uno zip');

    $risposta = mdrAvvia($this, mdrAmministratore(), $backup)
        ->assertStatus(422)
        ->assertJsonPath('errors.archive.0', mdrFrase('archive_not_zip', [], $lingua));

    mdrTradotta('archive_not_zip', [], $lingua);
    expect($risposta->getContent())->not->toContain(Storage::disk('backups')->path(''))
        ->not->toContain($backup->filename);
})->with(['it', 'en', 'pt', 'es']);

test('all\'avvio, un backup in esecuzione si legge nella lingua dell\'installazione', function (string $lingua) {
    mdrLingua($lingua);
    $utente = mdrAmministratore();
    $backup = mdrArchivio(mdrVoci(config('app.version')));
    Backup::create(['uuid' => (string) Str::uuid(), 'disk' => 'backups', 'status' => BackupStatus::DUMPING_DATABASE, 'type' => 'full',
        'started_at' => now(), 'updated_at' => now()]);

    mdrAvvia($this, $utente, $backup)
        ->assertStatus(422)
        ->assertJsonPath('errors.archive.0', mdrFrase('backup_running', [], $lingua));

    mdrTradotta('backup_running', [], $lingua);
})->with(['it', 'en', 'pt', 'es']);

test('all\'avvio, un ripristino già in corso si legge nella lingua dell\'installazione', function (string $lingua) {
    mdrLingua($lingua);
    $backup = mdrArchivio(mdrVoci(config('app.version')));
    app(RestoreState::class)->put(['uuid' => 'r-in-corso', 'phase' => 'importing_database', 'started_at' => time()]);

    mdrAvvia($this, mdrAmministratore(), $backup)
        ->assertStatus(422)
        ->assertJsonPath('errors.archive.0', mdrFrase('restore_running', [], $lingua));

    mdrTradotta('restore_running', [], $lingua);
})->with(['it', 'en', 'pt', 'es']);

test('all\'avvio, un ripristino che un\'altra richiesta sta avviando si legge nella lingua dell\'installazione', function (string $lingua) {
    mdrLingua($lingua);
    $backup = mdrArchivio(mdrVoci(config('app.version')));
    $altraRichiesta = new RestoreLock;
    expect($altraRichiesta->acquire())->toBeTrue();

    try {
        mdrAvvia($this, mdrAmministratore(), $backup)
            ->assertStatus(422)
            ->assertJsonPath('errors.archive.0', mdrFrase('restore_busy', [], $lingua));
    } finally {
        $altraRichiesta->release();
    }

    mdrTradotta('restore_busy', [], $lingua);
    expect(app(RestoreMode::class)->active())->toBeFalse();
})->with(['it', 'en', 'pt', 'es']);

test('all\'ispezione di un archivio, la password sbagliata si legge nella lingua dell\'installazione', function () {
    mdrLingua('en');
    $backup = mdrArchivio(mdrVoci(config('app.version')), 'password-giusta');

    $this->actingAs(mdrAmministratore())
        ->postJson("/impostazioni/backups/{$backup->uuid}/ripristina/ispeziona", ['password' => 'password-sbagliata'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', mdrFrase('archive_password', [], 'en'));

    mdrTradotta('archive_password', [], 'en');
});

test('una voce che non può stare in un backup, trovata durante l\'estrazione, arriva alla finestra nella lingua dell\'installazione', function () {
    mdrLingua('en');
    $backup = mdrArchivio(mdrVoci(config('app.version')) + ['altro/estraneo.txt' => 'x']);

    $avvio = mdrAvvia($this, mdrAmministratore(), $backup, null, false)->assertOk();
    // Lo step, come lo chiede la finestra dei backup: con il token, e l'errore nel log del riquadro.
    $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $avvio->json('token')]);

    $passo->assertOk()
        ->assertJsonPath('restore.phase', 'failed')
        ->assertJsonPath('restore.error', mdrFrase('entry_unexpected', ['name' => 'altro/estraneo.txt'], 'en'));

    mdrTradotta('entry_unexpected', ['name' => 'altro/estraneo.txt'], 'en');
});

test('una voce con un segmento «.» o «..» arriva alla finestra con una frase vera in tutti e due i casi: il percorso non è valido, senza dire dove porta', function (string $voce) {
    mdrLingua('it');
    $backup = mdrArchivio(mdrVoci(config('app.version')) + [$voce => 'x']);

    $avvio = mdrAvvia($this, mdrAmministratore(), $backup, null, false)->assertOk();
    $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $avvio->json('token')]);

    $passo->assertOk()
        ->assertJsonPath('restore.phase', 'failed')
        ->assertJsonPath('restore.error', mdrFrase('entry_traversal', ['name' => $voce], 'it'));

    // «files/./x» resta dentro la cartella di estrazione: la frase non può dire che ne esce, in nessuna lingua.
    foreach (['it' => 'fuori', 'en' => 'outside', 'pt' => 'fora', 'es' => 'fuera'] as $lingua => $fuori) {
        mdrTradotta('entry_traversal', ['name' => $voce], $lingua);
        expect(mb_strtolower(mdrFrase('entry_traversal', ['name' => $voce], $lingua)))->not->toContain($fuori);
    }
})->with(['files/./storage/app/x.txt', 'files/../x.txt']);

test('l\'archivio tolto dal disco dopo l\'avvio: lo step lo dice nella lingua dell\'installazione, senza il percorso del file sul server, che resta nel log del server', function (string $lingua) {
    mdrLingua($lingua);
    $log = mdrLogDelServer();
    $backup = mdrArchivio(mdrVoci(config('app.version')));
    $avvio = mdrAvvia($this, mdrAmministratore(), $backup, null, false)->assertOk();
    $percorso = Storage::disk('backups')->path($backup->filename);
    unlink($percorso);

    $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $avvio->json('token')]);

    $passo->assertOk()
        ->assertJsonPath('restore.phase', 'failed')
        ->assertJsonPath('restore.error', mdrFrase('archive_missing', [], $lingua));
    mdrTradotta('archive_missing', [], $lingua);
    expect(mdrComeLaLegge($passo))->not->toContain(Storage::disk('backups')->path(''))
        ->not->toContain($backup->filename)
        ->and(File::get($log))->toContain($percorso);
})->with(['it', 'en', 'pt', 'es']);

test('la cartella di estrazione o il file estratto che non si possono scrivere: lo step lo dice nella lingua dell\'installazione, senza il percorso, che resta nel log del server', function (string $caso) {
    mdrLingua('en');
    $log = mdrLogDelServer();
    $backup = mdrArchivio(mdrVoci(config('app.version')));
    $avvio = mdrAvvia($this, mdrAmministratore(), $backup, null, false)->assertOk();
    $radice = rtrim((string) config('backup.tmp_path'), '/').'/restore-'.$avvio->json('uuid');
    // La prima voce dell'archivio è manifest.json, nella cartella di estrazione.
    $bloccata = $caso === 'la cartella' ? $radice : $radice.'/extracted';
    $atteso = $caso === 'la cartella' ? $radice.'/extracted' : $radice.'/extracted/manifest.json';
    File::ensureDirectoryExists($bloccata);
    chmod($bloccata, 0555);

    try {
        $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $avvio->json('token')]);
    } finally {
        chmod($bloccata, 0755);
        File::deleteDirectory($radice);
    }

    $passo->assertOk()
        ->assertJsonPath('restore.phase', 'failed')
        ->assertJsonPath('restore.error', mdrFrase('extract_write_failed', [], 'en'));
    mdrTradotta('extract_write_failed', [], 'en');
    // Nel log del server il percorso e la causa che ha dato il sistema.
    expect(mdrComeLaLegge($passo))->not->toContain($radice)
        ->and(File::get($log))->toContain($atteso)
        ->and(File::get($log))->toContain('Permission denied');
})->with(['la cartella', 'il file']);

test('il backup di sicurezza che non riesce ferma il ripristino prima di toccare il database: lo step lo dice nella lingua dell\'installazione, con la casella da togliere com\'è scritta nella finestra, e la causa va nel log del server', function (string $lingua) {
    mdrLingua($lingua);
    $log = mdrLogDelServer();
    // Il database dei test è in memoria: il backup di sicurezza non riesce («non è un file su disco»). La voce in più
    // ferma l'estrazione: un ripristino che andasse avanti lo stesso si fermerebbe lì, con un altro errore.
    $backup = mdrArchivio(mdrVoci(config('app.version')) + ['altro/estraneo.txt' => 'x']);

    $avvio = mdrAvvia($this, mdrAmministratore(), $backup)->assertOk();
    $passo = $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $avvio->json('token')]);

    $casella = __('impostazioni.label.restore_safety_backup', [], $lingua);
    $passo->assertOk()
        ->assertJsonPath('restore.phase', 'failed')
        ->assertJsonPath('restore.failed_phase', 'safety_backup')
        ->assertJsonPath('restore.error', mdrFrase('safety_backup_failed', ['casella' => $casella], $lingua));
    mdrTradotta('safety_backup_failed', ['casella' => $casella], $lingua);
    expect($passo->json('restore.error'))->toContain($casella)
        ->and(File::get($log))->toContain('non è un file su disco');
})->with(['it', 'en', 'pt', 'es']);
