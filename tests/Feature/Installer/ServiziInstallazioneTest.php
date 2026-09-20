<?php

use App\Models\User;
use App\Services\Installer\ChiudiInstallazione;
use App\Services\Installer\CreaAmministratore;
use App\Services\Installer\PreparaDatabase;
use App\Services\Installer\ScriviImpostazioni;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/*
 * I servizi estratti dal wizard (1.11.0-beta.32): li usano sia il wizard sia `km:install`.
 * Qui si fissa che facciano esattamente ciò che i passi del wizard facevano.
 */

it('crea l\'amministratore con le regole del wizard, verificato e con il ruolo', function () {
    Role::findOrCreate('amministratore', 'web');

    $user = app(CreaAmministratore::class)->esegui('  Mario Rossi ', ' mario@esempio.it ', 'segreta1');

    expect($user->name)->toBe('Mario Rossi')
        ->and($user->email)->toBe('mario@esempio.it')
        ->and(Hash::check('segreta1', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole('amministratore'))->toBeTrue();
});

it('rifiuta nome corto, email doppia e password corta senza creare nulla', function () {
    User::factory()->create(['email' => 'gia@esempio.it']);

    foreach ([
        ['M', 'nuovo@esempio.it', 'segreta1'],
        ['Mario Rossi', 'gia@esempio.it', 'segreta1'],
        ['Mario Rossi', 'nuovo@esempio.it', 'corta'],
        ['Mario Rossi', 'non-una-email', 'segreta1'],
    ] as [$name, $email, $password]) {
        expect(fn () => app(CreaAmministratore::class)->esegui($name, $email, $password))
            ->toThrow(ValidationException::class);
    }

    expect(User::count())->toBe(1);
});

it('se il ruolo non esiste a database l\'utente nasce senza ruolo, come nel wizard', function () {
    $user = app(CreaAmministratore::class)->esegui('Mario Rossi', 'mario@esempio.it', 'segreta1');

    expect($user->roles)->toHaveCount(0);
});

it('scrive e legge il lock di installazione', function () {
    $lock = sys_get_temp_dir().'/km-test-'.uniqid().'.lock';
    config(['installer.options.lock_file' => $lock]);

    $chiudi = app(ChiudiInstallazione::class);
    expect($chiudi->installata())->toBeFalse();

    $chiudi->esegui();

    expect($chiudi->installata())->toBeTrue()
        ->and(File::get($lock))->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');

    File::delete($lock);
});

it('scrive nome e lingua nelle impostazioni generali, ignorando una lingua non prevista', function () {
    app(ScriviImpostazioni::class)->esegui('Studio Rossi', 'en');

    $settings = app(GeneralSettings::class);
    expect($settings->app_name)->toBe('Studio Rossi')->and($settings->language)->toBe('en');

    app(ScriviImpostazioni::class)->esegui(null, 'xx');

    expect(app(GeneralSettings::class)->language)->toBe('en');
});

it('migra senza fresh su un database già migrato senza distruggere nulla, e seedRuoli crea i ruoli', function () {
    User::factory()->create(['email' => 'resta@esempio.it']);

    $prepara = app(PreparaDatabase::class);
    $prepara->migra(fresh: false);
    $prepara->pulisciCache();
    $prepara->seedRuoli();

    expect(User::where('email', 'resta@esempio.it')->exists())->toBeTrue()
        ->and(Role::where('name', 'amministratore')->exists())->toBeTrue();
});
