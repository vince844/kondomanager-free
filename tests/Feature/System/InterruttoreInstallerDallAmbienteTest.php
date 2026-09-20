<?php

/**
 * # `INSTALLER_ENABLED`: l'interruttore dell'installer letto dall'ambiente (1.11.0-beta.32)
 *
 * `config('installer.run_installer')` accende tre cose: il wizard `/install`, il controllo degli
 * aggiornamenti e l'aggiornamento automatico. Nel repository è il letterale `false`; nello zip
 * pubblicato lo script di build lo porta a `true` **sostituendo quella riga**. In un container il
 * codice non si ritocca, quindi la chiave si sovrascrive dall'ambiente — ma solo se la variabile
 * esiste: senza, vale il letterale e per lo zip non cambia nulla.
 *
 * ## Cosa presidiano questi test
 *
 * 1. **La forma della riga letterale**: lo script di build la cerca com'è. Se qualcuno la
 *    riscrive come `env('INSTALLER_ENABLED', false)` — che sembra la cosa ovvia — lo zip esce con
 *    il wizard spento e nessuno se ne accorge fino alla prima installazione.
 * 2. **La precedenza**: variabile presente → vince; assente o vuota → letterale.
 * 3. **L'effetto**: con la chiave a `false`, `/install` rimanda alla radice e le rotte
 *    dell'aggiornamento automatico rispondono 403.
 *
 * ## Cosa NON copre
 *
 * Il file di configurazione viene riletto qui con `require`, non riavviando l'applicazione: si
 * prova la funzione «ambiente → valore della chiave», non che `config:cache` la congeli (lo fa,
 * come per ogni altra chiave che legge `env()`).
 */

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Il file di configurazione con il letterale nello stato dello zip (`true`) o del repository
 * (`false`), riletto con la variabile impostata. Sulla copia con `true` il test morde davvero: con
 * il letterale `false` non si distingue «ha vinto il letterale» da «ha vinto la variabile a false».
 */
function installerConAmbiente(?string $valore, bool $letterale = false): bool
{
    $sorgente = file_get_contents(config_path('installer.php'));
    if ($letterale) {
        $sorgente = str_replace("\n    'run_installer' => false,\n", "\n    'run_installer' => true,\n", $sorgente);
    }
    $copia = sys_get_temp_dir().'/km-installer-'.uniqid().'.php';
    file_put_contents($copia, $sorgente);

    $vecchio = $_ENV['INSTALLER_ENABLED'] ?? null;

    if ($valore === null) {
        unset($_ENV['INSTALLER_ENABLED'], $_SERVER['INSTALLER_ENABLED']);
    } else {
        $_ENV['INSTALLER_ENABLED'] = $_SERVER['INSTALLER_ENABLED'] = $valore;
    }

    try {
        return (require $copia)['run_installer'];
    } finally {
        unlink($copia);
        if ($vecchio === null) {
            unset($_ENV['INSTALLER_ENABLED'], $_SERVER['INSTALLER_ENABLED']);
        } else {
            $_ENV['INSTALLER_ENABLED'] = $_SERVER['INSTALLER_ENABLED'] = $vecchio;
        }
    }
}

it('tiene la riga letterale nella forma che lo script di build sostituisce', function () {
    $sorgente = file_get_contents(config_path('installer.php'));

    expect(substr_count($sorgente, "\n    'run_installer' => false,\n"))->toBe(1)
        ->and($sorgente)->not->toContain("'run_installer' => env(");
});

it('senza la variabile, o con la variabile vuota, vale il letterale: false nel repository, true nello zip', function () {
    expect(installerConAmbiente(null))->toBeFalse()
        ->and(installerConAmbiente(''))->toBeFalse()
        ->and(installerConAmbiente(null, letterale: true))->toBeTrue()
        ->and(installerConAmbiente('', letterale: true))->toBeTrue();
});

it('con la variabile presente vince la variabile, in entrambi i versi e su entrambi i letterali', function () {
    expect(installerConAmbiente('true'))->toBeTrue()
        ->and(installerConAmbiente('1'))->toBeTrue()
        ->and(installerConAmbiente('false', letterale: true))->toBeFalse()
        ->and(installerConAmbiente('0', letterale: true))->toBeFalse();
});

it('con l\'interruttore spento il wizard rimanda alla radice e l\'aggiornamento automatico è negato', function () {
    config()->set('installer.run_installer', false);

    $this->get('/install')->assertRedirect('/');

    Role::findOrCreate('amministratore', 'web');
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('amministratore');

    $this->actingAs($admin)
        ->postJson(route('system.upgrade.launch'))
        ->assertForbidden();
});

it('documenta la variabile in .env.example, commentata', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain("\n# INSTALLER_ENABLED=false\n")
        ->and($env)->toContain("\n# INSTALL_ADMIN_EMAIL=\n")
        ->and($env)->not->toMatch('/^INSTALLER_ENABLED=/m');
});
