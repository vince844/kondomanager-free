<?php

/**
 * `users.last_login_at` — l'ultimo ingresso di ogni utente.
 *
 * ## Perché questo file esiste
 *
 * La colonna serve a rispondere alla domanda che l'amministratore si fa davvero: *questo condòmino
 * ha mai aperto il portale?* Se non l'ha mai fatto, la convocazione va mandata cartacea. Ed è la
 * colonna che dice **quali utenze sospendere**, quindi si regge insieme al resto della beta.
 *
 * Un solo ascoltatore sull'evento `Login` copre tutte e tre le porte, perché passano tutte da
 * `SessionGuard`. Questi test lo dimostrano porta per porta: se domani qualcuno scrivesse la data
 * in un controller, la prima porta a restare indietro sarebbe la doppia autenticazione, che è
 * l'unica a saltare `LoginRequest`.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il ripristino da cookie *remember me*: passa dallo stesso evento, ma esercitarlo in
 * un test richiede di simulare il recaller, e la copertura che se ne ricava è la stessa riga di
 * codice già coperta due volte. Non copre l'ordinamento sulla colonna, coperto dai test
 * dell'ordinamento.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    \Spatie\Permission\Models\Permission::firstOrCreate([
        'name'       => \App\Enums\Permission::ACCESS_ADMIN_PANEL->value,
        'guard_name' => 'web',
    ]);
});

it('nasce vuoto: chi non è mai entrato non ha una data', function () {
    $utente = User::factory()->create();

    expect($utente->last_login_at)->toBeNull();
});

it('il login dal modulo lo scrive', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    $this->post('/login', ['email' => $utente->email, 'password' => 'password']);

    expect($utente->fresh()->last_login_at)->not->toBeNull();
});

it('anche la doppia autenticazione lo scrive, ed è la porta che salta il modulo', function () {
    $segreto = (new Google2FA)->generateSecretKey();

    $utente = User::factory()->create([
        'email_verified_at'       => now(),
        'two_factor_secret'       => encrypt($segreto),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->withSession(['login.id' => $utente->id, 'login.remember' => false])
        ->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($segreto)]);

    $this->assertAuthenticated();
    expect($utente->fresh()->last_login_at)->not->toBeNull();
});

it('un tentativo fallito non lo tocca', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    $this->post('/login', ['email' => $utente->email, 'password' => 'password-sbagliata']);

    expect($utente->fresh()->last_login_at)->toBeNull();
});

it('un utente sospeso non lascia traccia di ingresso', function () {
    // La sospensione respinge **dopo** aver verificato le credenziali: senza questo test, un
    // giorno la data direbbe che è entrato qualcuno che è stato rimandato indietro.
    $utente = User::factory()->create([
        'email_verified_at' => now(),
        'suspended_at'      => now(),
    ]);

    $this->post('/login', ['email' => $utente->email, 'password' => 'password']);

    $this->assertGuest();
    expect($utente->fresh()->last_login_at)->toBeNull();
});

it('scrivere l\'accesso non tocca updated_at', function () {
    // Se `updated_at` seguisse gli accessi, direbbe «ultimo ingresso» invece di «ultima modifica
    // alla scheda»: due informazioni diverse collassate in una, e quella che si perde è l'unica
    // che racconta chi ha messo mano all'utenza.
    $utente = User::factory()->create(['email_verified_at' => now()]);

    DB::table('users')->where('id', $utente->id)->update(['updated_at' => now()->subYear()]);
    $primaDelLogin = User::find($utente->id)->updated_at;

    $this->post('/login', ['email' => $utente->email, 'password' => 'password']);

    expect(User::find($utente->id)->updated_at->timestamp)->toBe($primaDelLogin->timestamp)
        ->and(User::find($utente->id)->last_login_at)->not->toBeNull();
});
