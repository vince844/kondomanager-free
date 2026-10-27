<?php

use App\Models\Condominio;
use App\Models\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * # Coda 312 — il controllo di quadratura del giornale è una rotta di sviluppo
 *
 * `/admin/test-quadratura/{condominio}` restituisce in JSON il bilancio di verifica dell'intero
 * giornale di un condominio, non è collegata a nessuna pagina e carica tutte le scritture in memoria.
 * È lo strumento con cui si prova il giornale durante lo sviluppo (`DeepScanContabileTest`), non una
 * funzione del gestionale: si registra solo negli ambienti `local` e `testing` (`testing` perché i test
 * la usano, `phpunit.xml` mette `APP_ENV=testing`). In ogni altro ambiente la rotta non esiste: 404.
 *
 * Le rotte si leggono all'avvio: per provare un altro ambiente il test cambia l'ambiente e rilegge
 * `routes/admin.php` in una tabella delle rotte vuota, come fa l'applicazione quando parte.
 */
function rileggiRottePannelloIn(string $ambiente): void
{
    app()['env'] = $ambiente;

    $router = app('router');
    $router->setRoutes(new RouteCollection);
    Route::middleware('web')->group(base_path('routes/admin.php'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

beforeEach(function () {
    app()[Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    Role::findOrCreate('amministratore', 'web');
    Permission::findOrCreate('Accesso pannello amministratore', 'web');

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole('amministratore');
    $this->condominio = Condominio::factory()->create();
});

afterEach(function () {
    app()['env'] = 'testing';
});

it('Coda 312 — fuori da local e testing la rotta della quadratura non esiste', function (string $ambiente) {
    rileggiRottePannelloIn($ambiente);

    // La dashboard, dalla stessa tabella riletta: la rilettura ha registrato il pannello.
    expect(app('router')->getRoutes()->getByName('admin.dashboard'))->not->toBeNull();

    $this->actingAs($this->amministratore)
        ->getJson("/admin/test-quadratura/{$this->condominio->id}")
        ->assertNotFound();
})->with(['production', 'staging']);

it('Coda 312 — in local e testing la rotta della quadratura c\'è e risponde', function (string $ambiente) {
    rileggiRottePannelloIn($ambiente);

    $this->actingAs($this->amministratore)
        ->getJson("/admin/test-quadratura/{$this->condominio->id}")
        ->assertOk()
        ->assertJsonStructure(['status']);
})->with(['local', 'testing']);
