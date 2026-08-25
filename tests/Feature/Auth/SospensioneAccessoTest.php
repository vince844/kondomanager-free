<?php

/**
 * La sospensione di un utente deve impedire l'accesso.
 *
 * ## Perché questo file esiste
 *
 * Fino alla beta.55 `suspended_at` era **soltanto un'etichetta rossa** nell'elenco utenti: il
 * middleware `CheckSuspendedUser` esiste ed è scritto bene, ma non era agganciato a nessuna delle
 * 439 rotte — l'unica che lo montava, `dashboard`, è stata commentata nell'aprile 2025 e il
 * middleware è rimasto orfano. Nessun percorso di autenticazione guardava la colonna.
 *
 * Nessun test copriva l'area: è il motivo per cui la regressione è sopravvissuta sedici mesi.
 *
 * I tre percorsi che portano dentro sono coperti qui uno per uno, perché sono davvero tre:
 * il login normale, la sfida 2FA — che **salta** `LoginRequest::authenticate()` — e la sessione
 * già aperta, che nessun controllo al login potrebbe intercettare.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il ripristino da cookie *remember me* (stesso evento `Login`, ma percorso diverso),
 * non copre i link firmati delle comunicazioni né il reset password, che restano raggiungibili da
 * un utente sospeso: è una domanda aperta, non un difetto già deciso.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    \Spatie\Permission\Models\Permission::firstOrCreate([
        'name'       => \App\Enums\Permission::ACCESS_ADMIN_PANEL->value,
        'guard_name' => 'web',
    ]);
});

it('un utente sospeso non supera il login', function () {
    $utente = User::factory()->create([
        'email_verified_at' => now(),
        'suspended_at'      => now(),
    ]);

    $this->post('/login', [
        'email'    => $utente->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('un utente sospeso con la doppia autenticazione non arriva nemmeno alla sfida', function () {
    $segreto = (new Google2FA)->generateSecretKey();

    $utente = User::factory()->create([
        'email_verified_at'       => now(),
        'suspended_at'            => now(),
        'two_factor_secret'       => encrypt($segreto),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post('/login', [
        'email'    => $utente->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();

    // La sfida non deve nemmeno aprirsi: senza `login.id` in sessione il middleware
    // `ensure-two-factor-challenge-session` non lascia entrare in `/two-factor-challenge`.
    expect(session()->has('login.id'))->toBeFalse();
});

it('la sfida 2FA rifiuta un utente sospeso anche con il codice giusto', function () {
    $segreto = (new Google2FA)->generateSecretKey();

    $utente = User::factory()->create([
        'email_verified_at'       => now(),
        'suspended_at'            => now(),
        'two_factor_secret'       => encrypt($segreto),
        'two_factor_confirmed_at' => now(),
    ]);

    // Si entra nella sfida come se il login l'avesse aperta: è lo stato che il
    // middleware `ensure-two-factor-challenge-session` si aspetta di trovare.
    $this->withSession(['login.id' => $utente->id, 'login.remember' => false])
        ->post('/two-factor-challenge', [
            'code' => (new Google2FA)->getCurrentOtp($segreto),
        ]);

    $this->assertGuest();
});

it('sospendere un utente lo butta fuori dalla sessione già aperta', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($utente);

    // La sospensione arriva mentre l'utente sta lavorando: è il caso normale, non l'eccezione.
    $utente->update(['suspended_at' => now()]);

    $this->get(route('user.dashboard'));

    $this->assertGuest();
});

it('un utente attivo continua a entrare', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    $this->post('/login', [
        'email'    => $utente->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
});
