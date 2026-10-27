<?php

/**
 * Coda 266 — l'installazione manuale non parte con il debug acceso né con un amministratore dalla
 * password pubblicata.
 *
 * ## Perché questo file esiste
 *
 * Un'installazione manuale su un server parte in produzione, con il debug spento, e
 * senza un amministratore dalla password pubblicata. Le scelte tecniche del
 * verbale: `.env.example` con `APP_ENV=production` e `APP_DEBUG=false`, e un
 * commento per chi sviluppa; il seeder crea l'amministratore con la password nota
 * solo in `local`, altrove con una password casuale stampata una volta; i
 * contenitori Docker di sviluppo restano in `local`; il README usa
 * `kondomanager:installa` per il server.
 *
 * ## Cosa questo file NON copre
 *
 * Il wizard e `kondomanager:installa`, che l'amministratore lo creano con i dati di chi installa
 * (`InstallaCommandTest.php`, `CreateAdminValidationTest.php`). Le guide Synology (Coda 181).
 */

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/** Le righe attive (non commentate) di un file `.env`, come chiave => valore. */
function righeAttiveDellEnv(string $percorso): array
{
    $righe = [];
    foreach (file($percorso, FILE_IGNORE_NEW_LINES) as $riga) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $riga, $m)) {
            $righe[$m[1]] = $m[2];
        }
    }

    return $righe;
}

/** Il blocco dell'entrypoint che prepara il `.env`, fino all'installazione delle dipendenze. */
function bloccoEnvDellEntrypoint(string $percorso): string
{
    $testo = file_get_contents($percorso);

    return substr($testo, 0, (int) strpos($testo, 'composer install'));
}

it('Coda 266 — `.env.example` è da produzione: niente ambiente locale, niente debug', function () {
    $env = righeAttiveDellEnv(base_path('.env.example'));

    expect($env['APP_ENV'] ?? null)->toBe('production')
        ->and($env['APP_DEBUG'] ?? null)->toBe('false');
});

it('Coda 266 — `.env.example` dice a chi sviluppa che cosa cambiare', function () {
    $testo = file_get_contents(base_path('.env.example'));

    expect($testo)->toMatch('/^#.*APP_ENV=local/m')
        ->and($testo)->toMatch('/^#.*APP_DEBUG=true/m');
});

it('Coda 266 — fuori da `local` il seeder crea l\'amministratore con una password casuale, e la stampa una volta', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    expect(app()->environment('local'))->toBeFalse();

    Artisan::call('db:seed', ['--class' => UserSeeder::class, '--force' => true]);
    $uscita = Artisan::output();

    $amministratore = User::where('email', 'admin@km.com')->firstOrFail();
    expect(Hash::check('password', $amministratore->password))->toBeFalse()
        ->and($amministratore->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeTrue();

    preg_match('/password: (\S+)/', $uscita, $trovata);
    expect($trovata[1] ?? null)->not->toBeNull()
        ->and(strlen($trovata[1]))->toBeGreaterThanOrEqual(10)
        ->and(Hash::check($trovata[1], $amministratore->password))->toBeTrue();
});

it('Coda 266 — due installazioni fuori da `local` non hanno la stessa password', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Artisan::call('db:seed', ['--class' => UserSeeder::class, '--force' => true]);
    preg_match('/password: (\S+)/', Artisan::output(), $prima);
    User::where('email', 'admin@km.com')->delete();

    Artisan::call('db:seed', ['--class' => UserSeeder::class, '--force' => true]);
    preg_match('/password: (\S+)/', Artisan::output(), $seconda);

    expect($prima[1] ?? null)->not->toBe($seconda[1] ?? null);
});

it('Coda 266 — in `local` il seeder crea l\'amministratore di sviluppo con la password delle guide', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'local';

    try {
        Artisan::call('db:seed', ['--class' => UserSeeder::class, '--force' => true]);
    } finally {
        $this->app['env'] = 'testing';
    }

    expect(Hash::check('password', User::where('email', 'admin@km.com')->firstOrFail()->password))->toBeTrue();
});

it('Coda 266 — il seeder generale non crea un secondo amministratore', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $presente = User::factory()->create(['email_verified_at' => now()]);
    $presente->assignRole(RoleEnum::AMMINISTRATORE->value);

    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect(User::where('email', 'admin@km.com')->exists())->toBeFalse()
        ->and(User::role(RoleEnum::AMMINISTRATORE->value)->count())->toBe(1);
});

it('Coda 266 — i contenitori Docker di sviluppo restano in `local` quando creano il `.env`', function (string $entrypoint) {
    $blocco = bloccoEnvDellEntrypoint(base_path($entrypoint));

    // Dentro il ramo che copia `.env.example`: un `.env` che c'è già non si tocca.
    preg_match('/if \[ ! -f "\.env" \]; then(.*?)\nfi\n/s', $blocco, $ramo);

    expect($ramo[1] ?? '')->toContain('cp .env.example .env')
        ->and($ramo[1] ?? '')->toContain("sed -i 's/^APP_ENV=.*/APP_ENV=local/' .env")
        ->and($ramo[1] ?? '')->toContain("sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' .env");
})->with([
    'standard'   => 'docker/standard/entrypoint.sh',
    'frankenphp' => 'docker/frankenphp/entrypoint.sh',
]);

it('Coda 266 — il README installa un server con `kondomanager:installa`, non con l\'amministratore di sviluppo', function (string $readme) {
    $testo = file_get_contents(base_path($readme));

    expect($testo)->toContain('php artisan kondomanager:installa')
        ->and($testo)->toContain('--admin-password=');
})->with(['README.md', 'README.en.md', 'README.pt-br.md']);
