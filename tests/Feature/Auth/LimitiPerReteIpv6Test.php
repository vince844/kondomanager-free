<?php

/**
 * Code 262 e 259 — i limiti per indirizzo contano la rete /64 di un indirizzo IPv6.
 *
 * ## Perché questo file esiste
 *
 * Chi ha una linea o un server con IPv6 riceve una rete /64 intera, cioè 2^64 indirizzi, e ne cambia
 * l'ultima parte a piacere: per questo i limiti per indirizzo contano la rete, non l'indirizzo intero.
 * Valgono così i cinque errori per email e indirizzo del login, il tetto di venti per indirizzo
 * (Coda 262), i tetti di rotta con nome del login, della sfida 2FA e dei moduli per ospiti (Code 259 e
 * 202) e il limite del recupero di un ripristino fallito.
 *
 * Un indirizzo IPv6 conta per la sua /64 e un indirizzo IPv4 per sé stesso, con un aiuto solo
 * (`App\Support\IndirizzoDeiLimiti`) usato da tutte le chiavi.
 *
 * ## Cosa questo file NON copre
 *
 * Il limite del recupero del ripristino, che sta con gli altri test del ripristino
 * (`tests/Feature/Restore/RestoreHttpTest.php`). Da quale indirizzo arriva la richiesta dietro un proxy
 * (`ProxyFidatiSenzaIndovinareTest`).
 */

use App\Models\User;
use App\Support\IndirizzoDeiLimiti;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Notification::fake();
});

/** Un indirizzo della rete /64 indicata, diverso per ogni `$n`. */
function indirizzoDellaRete(int $n, string $rete = '2a01:4f8:1:2'): string
{
    return sprintf('%s::%x', $rete, $n);
}

it('Coda 262 — venti login falliti da indirizzi diversi della stessa /64 fermano il ventunesimo, anche con la password giusta', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 20; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete($i)])
            ->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete(21)])
        ->post('/login', ['email' => $utente->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->toContain(trim(explode(':seconds', __('auth.throttle'))[0]));
});

it('Coda 262 — cinque password sbagliate sulla stessa email, cambiando l\'ultima parte dell\'indirizzo IPv6, fermano la sesta', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete($i)])
            ->post('/login', ['email' => $utente->email, 'password' => "sbagliata-{$i}"]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete(6)])
        ->post('/login', ['email' => $utente->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->toContain(trim(explode(':seconds', __('auth.throttle'))[0]));
});

it('Coda 262 — da un\'altra /64 si entra: il limite è della rete, non di tutti', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 20; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete($i)])
            ->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete(1, '2a01:4f8:1:3')])
        ->post('/login', ['email' => $utente->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($utente);
});

it('Coda 262 — un indirizzo IPv4 conta per sé stesso: gli errori di un vicino non fermano il login', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 20; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4'])
            ->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.5'])
        ->post('/login', ['email' => $utente->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($utente);
});

it('Coda 259 — il tetto di rotta del login conta la /64', function () {
    for ($i = 1; $i <= 60; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete($i)])
            ->post('/login', ['email' => "tentativo{$i}@example.com", 'password' => 'x'])
            ->assertStatus(302);
    }

    $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete(61)])
        ->post('/login', ['email' => 'tentativo61@example.com', 'password' => 'x'])
        ->assertStatus(429);
});

it('Coda 259 — il tetto di rotta della sfida 2FA conta la /64', function () {
    $segreto = (new Google2FA)->generateSecretKey();
    $utenti = collect(range(1, 7))->map(fn () => User::factory()->create([
        'email_verified_at'       => now(),
        'two_factor_secret'       => encrypt($segreto),
        'two_factor_confirmed_at' => now(),
    ]));
    $sessione = fn (User $utente) => ['login.id' => $utente->id, 'login.remember' => false, 'login.at' => now()->getTimestamp(), 'login.tentativi' => 0];

    // Un codice non numerico non è mai giusto, e conta come un errore.
    for ($i = 1; $i <= 30; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete($i)])
            ->withSession($sessione($utenti[$i % 7]))
            ->post('/two-factor-challenge', ['code' => 'nessuno'])
            ->assertStatus(302);
    }

    $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete(31)])
        ->withSession($sessione($utenti[31 % 7]))
        ->post('/two-factor-challenge', ['code' => 'nessuno'])
        ->assertStatus(429);
});

it('Coda 202 — il limite di un modulo per ospiti conta la /64', function () {
    for ($i = 1; $i <= 6; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete($i)])
            ->post('/forgot-password', ['email' => "qualcuno{$i}@example.com"])
            ->assertStatus(302);
    }

    $this->withServerVariables(['REMOTE_ADDR' => indirizzoDellaRete(7)])
        ->post('/forgot-password', ['email' => 'qualcuno7@example.com'])
        ->assertStatus(429);
});

it('Code 262 e 259 — l\'indirizzo dei limiti: IPv6 per la sua /64, IPv4 per sé stesso', function (?string $indirizzo, string $chiave) {
    expect(IndirizzoDeiLimiti::di($indirizzo))->toBe($chiave);
})->with([
    'IPv6'                             => ['2a01:4f8:1:2:aaaa:bbbb:cccc:dddd', '2a01:4f8:1:2::'],
    'IPv6 abbreviato'                  => ['2a01:4f8:1:2::3c', '2a01:4f8:1:2::'],
    'IPv4'                             => ['8.8.4.4', '8.8.4.4'],
    'IPv4 scritto come IPv6 (mappato)' => ['::ffff:8.8.4.4', '::ffff:8.8.4.4'],
    'loopback IPv6'                    => ['::1', '::1'],
    'nessun indirizzo'                 => [null, ''],
]);
