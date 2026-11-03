<?php

use App\Models\User;
use App\Services\UpdateService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * # Coda 342 — agli ospiti niente versione, stato degli aggiornamenti e tipo di installazione
 *
 * Ogni pagina Inertia porta nel suo JSON i valori condivisi da `HandleInertiaRequests::share()`. Le
 * pagine per ospiti (la pagina iniziale, il login, la registrazione, «password dimenticata», il reset)
 * non portano la versione installata, lo stato degli aggiornamenti (con la versione nuova, se c'è) né
 * la mappa delle funzioni dell'installazione, nemmeno nel sorgente della pagina: i tre valori vanno
 * solo a chi ha fatto l'accesso (decisione 127.6); agli ospiti `null`. Restano il nome, `is_demo` e
 * la tabella delle rotte.
 */
beforeEach(function () {
    config(['app.version' => '9.9.9-prova', 'installer.run_installer' => true]);
    app(UpdateService::class)->setUpdateAvailable('9.9.10-nuova');

    $impostazioni = app(GeneralSettings::class);
    $impostazioni->user_frontend_registration = true;
    $impostazioni->save();
});

afterEach(function () {
    Cache::flush();
});

it('Coda 342 — le pagine per ospiti non portano versione, aggiornamenti e funzioni', function (string $indirizzo, string $componente) {
    $risposta = $this->get($indirizzo)->assertOk();

    $risposta->assertInertia(fn (Assert $pagina) => $pagina
        ->component($componente)
        ->where('version', null)
        ->where('system_update', null)
        ->where('funzioni', null)
        ->where('name', config('app.name'))
        ->has('is_demo')
    );

    // Nel sorgente della pagina, che è ciò che legge chi non ha fatto l'accesso.
    $sorgente = $risposta->getContent();
    expect($sorgente)->not->toContain('9.9.9-prova')
        ->and($sorgente)->not->toContain('9.9.10-nuova')
        ->and($sorgente)->not->toContain('aggiornamenti_in_app');
})->with([
    'pagina iniziale' => ['/', 'Welcome'],
    'login' => ['/login', 'auth/Login'],
    'registrazione' => ['/register', 'auth/Register'],
    'password dimenticata' => ['/forgot-password', 'auth/ForgotPassword'],
    'reset della password' => ['/reset-password/un-token-qualsiasi', 'auth/ResetPassword'],
]);

it('Coda 342 — con l\'accesso versione, aggiornamenti e funzioni ci sono', function () {
    // Un utente qualsiasi con l'accesso: la regola guarda l'accesso, non il ruolo. (Un amministratore,
    // con la versione del programma più alta di quella nel database, andrebbe alla pagina
    // dell'aggiornamento: `CheckForPendingUpdates`.)
    $utente = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($utente)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('version', '9.9.9-prova')
            ->where('system_update.current_version', '9.9.9-prova')
            ->where('system_update.new_version', '9.9.10-nuova')
            ->where('system_update.available', true)
            ->where('funzioni.aggiornamenti_in_app', true)
        );
});
