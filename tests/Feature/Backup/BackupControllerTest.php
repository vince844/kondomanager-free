<?php

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\BackupStatus;
use App\Enums\Permission;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupPasswordStore;
use App\Services\Backup\Support\StepBudget;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeBackupDumper;

/**
 * Test HTTP della pagina backup: autorizzazioni, flusso store → step →
 * download, eliminazione e impostazioni di retention.
 */
function backupAdmin(): User
{
    /** @var User $user */
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value);

    return $user;
}

function backupFixture(string $relative = ''): string
{
    return base_path('storage/framework/testing/backup-http-fixture'.($relative !== '' ? '/'.$relative : ''));
}

beforeEach(function () {
    Storage::fake('backups');

    // La password salvata vive in un file su percorso reale (non sul disco
    // fake): va azzerata a ogni test per non trascinare stato tra i casi.
    app(BackupPasswordStore::class)->clear();

    $this->app->bind(DatabaseDumperInterface::class, fn () => new FakeBackupDumper);

    // I permessi devono esistere come righe anche per i test "senza permesso":
    // hasPermissionTo() lancia un'eccezione se il permesso non esiste affatto.
    foreach ([Permission::MANAGE_GENERAL_SETTINGS, Permission::ACCESS_ADMIN_PANEL] as $permission) {
        Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => $permission->value,
            'guard_name' => 'web',
        ]);
    }

    File::ensureDirectoryExists(backupFixture());
    File::put(backupFixture('documento.pdf'), str_repeat('PDF', 500));

    config()->set('backup.include', ['storage/framework/testing/backup-http-fixture']);
    config()->set('backup.exclude', []);
});

afterEach(function () {
    app(BackupPasswordStore::class)->clear();
    File::deleteDirectory(backupFixture());
    File::deleteDirectory(config('backup.tmp_path'));
});

test('un ospite viene rediretto al login', function () {
    $this->get('/impostazioni/backups')->assertRedirect('/login');
});

test('un utente senza permesso riceve 403', function () {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user)->get('/impostazioni/backups')->assertForbidden();
    $this->actingAs($user)->post('/impostazioni/backups')->assertForbidden();
});

test('un amministratore vede la pagina con preflight e lista backup', function () {
    $this->actingAs(backupAdmin())
        ->get('/impostazioni/backups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('impostazioni/impostazioniBackups')
            ->has('backups')
            ->has('preflight.checks', 4)
            ->where('preflight.ok', true)
            ->where('retention_keep_last', 5)
        );
});

test('il flusso completo store → step → download funziona', function () {
    $admin = backupAdmin();

    // Avvio: crea il backup ed esegue il primo step
    $response = $this->actingAs($admin)->postJson('/impostazioni/backups');
    $response->assertOk();

    $uuid = $response->json('backup.uuid');
    expect($uuid)->not->toBeNull();

    // Con il dumper finto e pochi file il primo step completa già tutto;
    // in ogni caso si continua a chiamare step finché non è terminale.
    $status = $response->json('backup.status');
    $guard = 0;

    while (! in_array($status, ['completed', 'failed'], true) && $guard < 10) {
        $step = $this->actingAs($admin)->postJson("/impostazioni/backups/{$uuid}/step");
        $step->assertOk();
        $status = $step->json('backup.status');
        $guard++;
    }

    expect($status)->toBe('completed');

    $backup = Backup::where('uuid', $uuid)->firstOrFail();

    // Download consentito all'amministratore, con la password ridigitata da poco: lo scarico
    // la chiede (Coda 261, decisione 127.7; vedi `ConfermaPasswordESessioniTest.php`).
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get("/impostazioni/backups/{$uuid}/download")
        ->assertOk()
        ->assertDownload($backup->filename);

    // Download negato a chi non ha il permesso
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get("/impostazioni/backups/{$uuid}/download")
        ->assertForbidden();
});

test('Coda 400 — la cartella dei temporanei che non si crea: il backup risulta fallito, con la ragione, e non resta in corso; a cartella sistemata, il backup dopo parte e arriva in fondo', function () {
    $admin = backupAdmin();
    $temporanei = config('backup.tmp_path');
    // Una cartella che non si può creare: dentro /dev/null, che non è una cartella.
    config(['backup.tmp_path' => '/dev/null/kondo-temporanei']);

    $primo = $this->actingAs($admin)->postJson('/impostazioni/backups', ['type' => 'db_only']);
    $righe = Backup::orderBy('id')->get()->map(fn (Backup $b) => $b->status->value)->all();
    // La cartella è sistemata.
    config(['backup.tmp_path' => $temporanei]);
    $secondo = $this->actingAs($admin)->postJson('/impostazioni/backups', ['type' => 'db_only']);
    $stato = $secondo->json('backup.status');
    for ($i = 0; $i < 10 && ! in_array($stato, ['completed', 'failed', null], true); $i++) {
        $stato = $this->actingAs($admin)->postJson('/impostazioni/backups/'.$secondo->json('backup.uuid').'/step')->json('backup.status');
    }

    expect($primo->status())->toBe(200)
        ->and($primo->json('backup.status'))->toBe('failed')
        ->and((string) $primo->json('backup.error'))->not->toBe('')
        ->and((string) $primo->json('backup.error'))->not->toContain('/dev/null')
        ->and($righe)->toBe(['failed'])
        ->and($secondo->status())->toBe(200)
        ->and($stato)->toBe('completed');
});

test('un secondo store mentre un backup è in corso restituisce 409', function () {
    $admin = backupAdmin();

    // Un backup in corso (bloccato a metà, non completato)
    $backup = Backup::create(['status' => BackupStatus::DUMPING_DATABASE, 'started_at' => now()]);

    $this->actingAs($admin)->postJson('/impostazioni/backups')->assertStatus(409);

    $backup->delete();
});

test('destroy elimina il backup e reindirizza con flash', function () {
    $admin = backupAdmin();

    $this->actingAs($admin)->postJson('/impostazioni/backups');
    $backup = Backup::firstOrFail();

    // Completa il backup
    $guard = 0;
    while ($backup->refresh()->isRunning() && $guard < 10) {
        $this->actingAs($admin)->postJson("/impostazioni/backups/{$backup->uuid}/step");
        $guard++;
    }

    $filename = $backup->refresh()->filename;

    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->delete("/impostazioni/backups/{$backup->uuid}")
        ->assertRedirect('/impostazioni/backups');

    expect(Backup::count())->toBe(0);
    Storage::disk('backups')->assertMissing($filename);
});

test('con i backup disabilitati (demo/gestito) tutte le rotte rispondono 404 e la card risulta disabilitata', function () {
    config()->set('backup.enabled', false);

    $admin = backupAdmin();

    // Anche un amministratore con tutti i permessi riceve 404: la funzione non esiste
    $this->actingAs($admin)->get('/impostazioni/backups')->assertNotFound();
    $this->actingAs($admin)->postJson('/impostazioni/backups')->assertNotFound();
    $this->actingAs($admin)->post('/impostazioni/backups/settings', ['retention_keep_last' => 3])->assertNotFound();

    $backup = Backup::create(['status' => BackupStatus::COMPLETED, 'filename' => 'x.zip']);
    $this->actingAs($admin)->postJson("/impostazioni/backups/{$backup->uuid}/step")->assertNotFound();
    $this->actingAs($admin)->get("/impostazioni/backups/{$backup->uuid}/download")->assertNotFound();
    $this->actingAs($admin)->delete("/impostazioni/backups/{$backup->uuid}")->assertNotFound();

    // La hub impostazioni segnala la card come disabilitata
    $this->actingAs($admin)
        ->get('/impostazioni')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('backups_enabled', false));
});

test('con i backup abilitati la hub espone backups_enabled true e lo stato di esecuzione', function () {
    $this->actingAs(backupAdmin())
        ->get('/impostazioni')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('backups_enabled', true)
            ->where('backup_running', false));

    // Con un backup in corso la hub lo segnala (badge sulla card)
    Backup::create(['status' => BackupStatus::DUMPING_DATABASE, 'started_at' => now()]);

    $this->actingAs(backupAdmin())
        ->get('/impostazioni')
        ->assertInertia(fn ($page) => $page->where('backup_running', true));
});

test('la validazione del tipo di backup rifiuta valori sconosciuti', function () {
    $this->actingAs(backupAdmin())
        ->postJson('/impostazioni/backups', ['type' => 'inventato'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');

    expect(Backup::count())->toBe(0);
});

test('la password di protezione viene validata, salvata e rimossa', function () {
    $admin = backupAdmin();
    $store = app(BackupPasswordStore::class);

    // Impostare e togliere la password dei backup chiede la password dell'account ridigitata da poco
    // (Coda 261, decisione 127.7; vedi `ConfermaPasswordESessioniTest.php`): qui è già confermata.
    $this->withSession(['auth.password_confirmed_at' => time()]);

    // La password si imposta insieme alle altre impostazioni (un solo
    // pulsante "Salva impostazioni" per tutta la card). Troppo corta:
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/settings', ['retention_keep_last' => 5, 'password' => 'corta', 'password_confirmation' => 'corta'])
        ->assertSessionHasErrors('password');

    // Conferma non coincidente: protezione dal typo che renderebbe
    // irrecuperabili tutti i backup futuri
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/settings', ['retention_keep_last' => 5, 'password' => 'password-lunga-1', 'password_confirmation' => 'password-lunga-2'])
        ->assertSessionHasErrors('password');

    expect($store->has())->toBeFalse();

    // Valida: salvata e recuperabile in chiaro solo lato server
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/settings', [
            'retention_keep_last' => 5,
            'password' => 'chiave-super-segreta',
            'password_confirmation' => 'chiave-super-segreta',
        ])
        ->assertRedirect('/impostazioni/backups');

    expect($store->has())->toBeTrue();
    expect($store->get())->toBe('chiave-super-segreta');

    // La pagina espone solo SE è impostata, mai il valore
    $this->actingAs($admin)
        ->get('/impostazioni/backups')
        ->assertInertia(fn ($page) => $page->where('backup_has_password', true));

    // Il salvataggio della sola retention NON tocca la password salvata
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/settings', ['retention_keep_last' => 7])
        ->assertRedirect('/impostazioni/backups');

    expect($store->get())->toBe('chiave-super-segreta');

    // Rimozione (endpoint dedicato con conferma)
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/password', ['remove' => true])
        ->assertRedirect('/impostazioni/backups');

    expect($store->has())->toBeFalse();
});

test('la password salvata non arriva mai al frontend', function () {
    app(BackupPasswordStore::class)->set('chiave-super-segreta');

    $response = $this->actingAs(backupAdmin())->get('/impostazioni/backups');

    $response->assertOk();
    // Il valore in chiaro non deve comparire da nessuna parte nella pagina
    expect($response->getContent())->not->toContain('chiave-super-segreta');
});

test('protect senza password salvata non crea un backup in chiaro silenziosamente', function () {
    $response = $this->actingAs(backupAdmin())->postJson('/impostazioni/backups', ['protect' => true]);

    $response->assertStatus(422);
    expect(Backup::count())->toBe(0);
});

test('senza flag protect il backup parte in chiaro anche con una password salvata', function () {
    app(BackupPasswordStore::class)->set('chiave-super-segreta');

    $response = $this->actingAs(backupAdmin())->postJson('/impostazioni/backups');

    $response->assertOk();
    expect($response->json('backup.encrypted'))->toBeFalse();
});

test('il flusso completo di un backup cifrato solo-database funziona da HTTP', function () {
    $admin = backupAdmin();

    // La password si imposta una volta sola nelle impostazioni
    app(BackupPasswordStore::class)->set('chiave-super-segreta');

    $response = $this->actingAs($admin)->postJson('/impostazioni/backups', [
        'type' => 'db_only',
        'protect' => true,
    ]);
    $response->assertOk();

    $uuid = $response->json('backup.uuid');
    $status = $response->json('backup.status');
    expect($response->json('backup.type'))->toBe('db_only');
    expect($response->json('backup.encrypted'))->toBeTrue();

    $guard = 0;

    while (! in_array($status, ['completed', 'failed'], true) && $guard < 10) {
        $status = $this->actingAs($admin)->postJson("/impostazioni/backups/{$uuid}/step")->json('backup.status');
        $guard++;
    }

    expect($status)->toBe('completed');

    $backup = Backup::where('uuid', $uuid)->firstOrFail();
    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path($backup->filename));

    // Cifrato: senza password il manifest non si legge
    expect($zip->getFromName('manifest.json'))->toBeFalse();
    $zip->setPassword('chiave-super-segreta');
    expect(json_decode((string) $zip->getFromName('manifest.json'), true)['contents'])->toBe('db_only');
    $zip->close();
});

test('le impostazioni di retention vengono validate e salvate', function () {
    $admin = backupAdmin();

    // Valore non valido
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/settings', ['retention_keep_last' => 0])
        ->assertSessionHasErrors('retention_keep_last');

    // Valore valido
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/settings', ['retention_keep_last' => 10])
        ->assertRedirect('/impostazioni/backups');

    expect(app(BackupSettings::class)->retention_keep_last)->toBe(10);
});

test('un errore del backup che porta un percorso del server arriva alla finestra dei backup senza il percorso, che resta nel log del server', function (string $caso) {
    $log = storage_path('framework/testing/log-errori-backup-'.(ParallelTesting::token() ?: 'serial').'.log');
    @unlink($log);
    config(['logging.channels.backup_di_prova' => ['driver' => 'single', 'path' => $log, 'level' => 'debug'], 'logging.default' => 'backup_di_prova']);
    // I temporanei e gli archivi si possono spostare fuori dalla cartella dell'installazione (`BACKUP_TMP_PATH`, `BACKUP_ROOT`).
    $fuori = sys_get_temp_dir().'/kondo-backup-'.Str::lower(Str::random(8));
    File::ensureDirectoryExists($fuori);
    if ($caso === 'un file nella cartella dei temporanei, fuori dall\'installazione') {
        config(['backup.tmp_path' => $fuori]);
    }
    if ($caso === 'un file nella cartella dei backup, fuori dall\'installazione') {
        config(['filesystems.disks.backups.root' => $fuori]);
        Storage::set('backups', Storage::build(['driver' => 'local', 'root' => $fuori]));
    }
    $percorso = match ($caso) {
        'un file dell\'installazione' => backupFixture('manca.sql'),
        'un file nella cartella dei temporanei, fuori dall\'installazione' => $fuori.'/manca/database.sql',
        'un file nella cartella dei backup, fuori dall\'installazione' => $fuori.'/manca.zip',
    };
    // Il dump si ferma sull'avviso del sistema su quel file: l'errore è l'avviso di PHP, con il percorso.
    $this->app->bind(DatabaseDumperInterface::class, fn () => new class($percorso) implements DatabaseDumperInterface
    {
        public function __construct(private readonly string $percorso) {}

        public function dumpFilename(): string
        {
            return 'database.sql';
        }

        public function dump(string $targetPath, array &$state, StepBudget $budget): bool
        {
            return file_get_contents($this->percorso) !== false;
        }
    });

    try {
        $avvio = $this->actingAs(backupAdmin())->postJson('/impostazioni/backups', ['type' => 'db_only']);
        $pagina = $this->actingAs(backupAdmin())->get('/impostazioni/backups');
    } finally {
        File::deleteDirectory($fuori);
    }

    $errore = (string) $avvio->json('backup.error');
    expect($avvio->json('backup.status'))->toBe('failed')
        ->and($errore)->toContain(basename($percorso))
        ->and($errore)->not->toContain(dirname($percorso))
        ->and($errore)->not->toMatch('#(^|[\s(\'":,=])/\w#')
        ->and(File::get($log))->toContain($percorso);
    $pagina->assertInertia(fn ($page) => $page->where('backups.data.0.error', $errore));
    @unlink($log);
})->with([
    'un file dell\'installazione',
    'un file nella cartella dei temporanei, fuori dall\'installazione',
    'un file nella cartella dei backup, fuori dall\'installazione',
]);

test('la cartella dei backup fuori dall\'installazione che non si crea: l\'errore arriva alla finestra dei backup senza la cartella, anche quando chiude la frase, e la cartella resta nel log del server', function () {
    $log = storage_path('framework/testing/log-errori-backup-'.(ParallelTesting::token() ?: 'serial').'.log');
    @unlink($log);
    config(['logging.channels.backup_di_prova' => ['driver' => 'single', 'path' => $log, 'level' => 'debug'], 'logging.default' => 'backup_di_prova']);
    // Una cartella che non si può creare: dentro /dev/null, che non è una cartella.
    $radice = '/dev/null/kondo-archivi';
    config(['filesystems.disks.backups.root' => $radice]);
    Storage::forgetDisk('backups');

    $avvio = $this->actingAs(backupAdmin())->postJson('/impostazioni/backups', ['type' => 'db_only']);
    $pagina = $this->actingAs(backupAdmin())->get('/impostazioni/backups');

    $errore = (string) $avvio->json('backup.error');
    expect($avvio->json('backup.status'))->toBe('failed')
        ->and($errore)->not->toBe('')
        ->and($errore)->not->toContain($radice)
        ->and($errore)->not->toMatch('#(^|[\s(\'":,=;])/\w#')
        ->and(File::get($log))->toContain($radice);
    $pagina->assertInertia(fn ($page) => $page->where('backups.data.0.error', $errore));
    @unlink($log);
});

test('l\'errore di un backup fallito salvato con il percorso del server, prima di questa versione, arriva alla finestra dei backup senza il percorso', function (?string $salvato, ?string $mostrato) {
    config(['backup.tmp_path' => '/var/tmp/kondo-temporanei', 'filesystems.disks.backups.root' => '/mnt/archivi-kondo']);
    $salvato = $salvato === null ? null : str_replace('{installazione}', base_path(), $salvato);
    Backup::create(['uuid' => (string) Str::uuid(), 'disk' => 'backups', 'status' => BackupStatus::FAILED, 'type' => 'db_only', 'error' => $salvato]);

    $this->actingAs(backupAdmin())
        ->get('/impostazioni/backups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('backups.data.0.error', $mostrato));
})->with([
    'un file dell\'installazione' => ['file_get_contents({installazione}/storage/app/backups/tmp/b-1/database.sql): Failed to open stream: No such file or directory', 'file_get_contents(…/storage/app/backups/tmp/b-1/database.sql): Failed to open stream: No such file or directory'],
    'la cartella dei temporanei, fuori dall\'installazione' => ['mkdir(): Permission denied (/var/tmp/kondo-temporanei)', 'mkdir(): Permission denied (…)'],
    'un archivio, nella cartella dei backup fuori dall\'installazione' => ['Impossibile spostare l\'archivio di backup in /mnt/archivi-kondo/kondomanager-backup-prova.zip', 'Impossibile spostare l\'archivio di backup in …/kondomanager-backup-prova.zip'],
    'un errore senza percorsi' => ['Un altro backup è già in esecuzione.', 'Un altro backup è già in esecuzione.'],
    'nessun errore salvato' => [null, null],
]);
