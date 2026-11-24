<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La demo pubblica si riconosce dalla configurazione, non dall'ambiente letto a mano (1.12.0-beta.14).
 *
 * L'immagine di produzione lancia `artisan optimize` (`docker/production/entrypoint.sh`): la configurazione è in cache, e `env('IS_DEMO')` letto fuori da
 * `config/` vale sempre falso. Fino alla 1.12.0-beta.13 `HandleInertiaRequests` mandava così `is_demo` falso anche nella demo; con la pagina d'errore
 * nuova, i visitatori della demo (ruolo «Amministratore demo», senza «Gestisci impostazioni generali») avrebbero visto «Avvisa lo studio». Ora la chiave
 * è `kondomanager.demo` (`IS_DEMO` letto in `config/kondomanager.php`), e ogni punto la legge con `config()`.
 *
 * Che cosa non copre: l'immagine vera con `artisan optimize`; qui la configurazione si accende con `config()`, come la mette la cache.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['app.debug' => false]);
    Route::middleware('web')->get('/prova-demo-errore', fn () => throw new \RuntimeException('Call to a member function nome() on null'));
});

function ddcUtente(string $ruolo): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole(Role::findByName($ruolo, 'web'));

    return $utente->fresh();
}

it('con la demo accesa nella configurazione (senza la variabile d\'ambiente) la pagina d\'errore non ha né l\'avviso né il dettaglio', function () {
    // La variabile d'ambiente è spenta (phpunit.xml la può mettere a «false»): la demo si accende solo dalla configurazione.
    expect(filter_var(getenv('IS_DEMO'), FILTER_VALIDATE_BOOL))->toBeFalse();
    config(['kondomanager.demo' => true]);

    foreach ([ddcUtente('utente'), ddcUtente('amministratore')] as $utente) {
        $html = $this->actingAs($utente)->get('/prova-demo-errore')->assertStatus(500)->getContent();
        expect($html)->toContain('Questa è la demo pubblica.')
            ->and($html)->not->toContain('name="firma"')
            ->and($html)->not->toContain('Dettaglio tecnico');
        auth()->logout();
    }
});

it('con la demo accesa nella configurazione l\'avviso non parte', function () {
    $utente = ddcUtente('utente');
    $html = $this->actingAs($utente)->get('/prova-demo-errore')->getContent();
    preg_match('/name="codice" value="([^"]+)"/', $html, $codice);
    preg_match('/name="firma" value="([^"]+)"/', $html, $firma);

    config(['kondomanager.demo' => true]);
    $this->actingAs($utente)->postJson(route('errori.avviso'), ['codice' => $codice[1], 'firma' => $firma[1]])->assertStatus(403);
});

it('la prop `is_demo` delle pagine viene dalla configurazione', function () {
    $admin = ddcUtente('amministratore');

    config(['kondomanager.demo' => true]);
    expect($this->actingAs($admin)->get(route('logs.index'))->assertOk()->viewData('page')['props']['is_demo'])->toBeTrue();

    config(['kondomanager.demo' => false]);
    expect($this->actingAs($admin)->get(route('logs.index'))->assertOk()->viewData('page')['props']['is_demo'])->toBeFalse();
});

it('nessun file fuori da config/ legge IS_DEMO con env(): con la configurazione in cache varrebbe sempre falso', function () {
    $lettori = [];
    foreach (['app', 'bootstrap', 'routes', 'database', 'resources/views'] as $cartella) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($cartella))) as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/env\(\s*[\'"]IS_DEMO[\'"]/', (string) file_get_contents($f->getPathname())) === 1) {
                $lettori[] = str_replace(base_path().'/', '', $f->getPathname());
            }
        }
    }

    expect($lettori)->toBe([])
        ->and((string) file_get_contents(config_path('kondomanager.php')))->toMatch('/\'demo\'\s*=>.*env\(\'IS_DEMO\'/');
});
