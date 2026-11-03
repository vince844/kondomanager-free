<?php

/**
 * Coda 259 — il limite ai tentativi vale anche con la 2FA, e la sfida 2FA è stretta.
 *
 * ## Perché questo file esiste
 *
 * Il blocco dei tentativi del login vale anche per gli account con la 2FA, e si guarda prima della
 * password: a blocco scattato nemmeno la password giusta porta alla pagina del codice. La sfida del
 * codice è stretta: una finestra corta, un limite per utente oltre a quello per utente e indirizzo,
 * nessun codice valido due volte, e lo stato «password giusta» che scade e si chiude dopo troppi
 * errori.
 * Le scelte tecniche del verbale: il blocco dopo cinque password sbagliate vale anche con la 2FA; la
 * sfida ha un limite per utente (cinque in cinque minuti) oltre a quello per utente e indirizzo; lo
 * stato «password giusta» si chiude dopo cinque codici sbagliati o dieci minuti; la finestra è di ±30
 * secondi; l'ultimo codice usato si ricorda in cache, per utente.
 *
 * Con la sfida, la Coda 202 (punto 2): i limiti di rotta senza nome (`throttle:6,1`) contavano
 * insieme tutte le rotte per ospiti dello stesso indirizzo, e per un utente collegato tutte le sue.
 *
 * ## Cosa questo file NON copre
 *
 * Il tetto per indirizzo del login (Coda 262, `PasswordDiDieciCaratteriTest.php`), salvo il suo
 * effetto sul ramo della 2FA. La 2FA obbligatoria (Coda 384).
 */

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Notification::fake();
    \Spatie\Permission\Models\Permission::firstOrCreate([
        'name'       => \App\Enums\Permission::ACCESS_ADMIN_PANEL->value,
        'guard_name' => 'web',
    ]);

    $this->segreto = (new Google2FA)->generateSecretKey();
    $this->conDueFattori = User::factory()->create([
        'email_verified_at'         => now(),
        'two_factor_secret'         => encrypt($this->segreto),
        'two_factor_recovery_codes' => encrypt(json_encode(['recupero-uno-0001', 'recupero-due-0002'])),
        'two_factor_confirmed_at'   => now(),
    ]);
});

/**
 * Il codice di `$scarto` periodi di 30 secondi da adesso (0 = quello di adesso).
 *
 * La libreria legge l'orologio vero, non quello dei test: negli ultimi tre secondi di un periodo si
 * aspetta il successivo, così il codice e la sua verifica cadono nello stesso periodo.
 */
function codiceA(string $segreto, int $scarto = 0): string
{
    $secondo = fmod(microtime(true), 30);
    if ($secondo > 27) {
        usleep((int) ((30 - $secondo) * 1_000_000) + 100_000);
    }

    $g = new Google2FA;

    return $g->oathTotp($segreto, $g->getTimestamp() + $scarto);
}

/** Un codice di sei cifre che non è nessuno dei tre validi adesso. */
function codiceSbagliato(string $segreto): string
{
    $validi = [codiceA($segreto, -1), codiceA($segreto), codiceA($segreto, 1)];

    for ($n = 0; in_array(sprintf('%06d', $n), $validi, true); $n++);

    return sprintf('%06d', $n);
}

/** La sessione come la lascia la password giusta: è lo stato che la sfida si aspetta. */
function sessioneDellaSfida(User $utente): array
{
    return ['login.id' => $utente->id, 'login.remember' => false, 'login.at' => now()->getTimestamp(), 'login.tentativi' => 0];
}

it('Coda 259 — dopo cinque password sbagliate, la password giusta di un account con la 2FA è fermata dal limite', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'sbagliata-'.$i]);
    }

    $risposta = $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'password']);

    $risposta->assertSessionHasErrors('email');
    expect($risposta->headers->get('Location'))->not->toContain('two-factor-challenge');
    expect(session()->has('login.id'))->toBeFalse();
    expect(session('errors')->first('email'))->toContain(trim(explode(':seconds', __('auth.throttle'))[0]));
});

it('Coda 259 — il tetto per indirizzo ferma anche la password giusta di un account con la 2FA', function () {
    for ($i = 1; $i <= 20; $i++) {
        $this->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    expect(session()->has('login.id'))->toBeFalse();
});

it('Coda 259 — la password giusta di un account con la 2FA non azzera il contatore delle sbagliate', function () {
    for ($i = 1; $i <= 4; $i++) {
        $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'sbagliata-'.$i]);
    }

    $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'sbagliata-5']);

    $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
});

it('Coda 259 — sotto il limite la password giusta porta alla pagina del codice, e il codice giusto fa entrare', function () {
    $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'sbagliata'])
        ->assertSessionHasErrors('email');

    $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    $this->post('/two-factor-challenge', ['code' => codiceA($this->segreto)]);

    $this->assertAuthenticatedAs($this->conDueFattori);
});

it('Coda 259 — la sfida si ferma per utente, anche cambiando indirizzo e sessione', function () {
    foreach (['10.0.0.1', '10.0.0.1', '10.0.0.1', '10.0.0.2', '10.0.0.2'] as $indirizzo) {
        $this->withServerVariables(['REMOTE_ADDR' => $indirizzo])
            ->withSession(sessioneDellaSfida($this->conDueFattori))
            ->post('/two-factor-challenge', ['code' => codiceSbagliato($this->segreto)]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
        ->withSession(sessioneDellaSfida($this->conDueFattori))
        ->post('/two-factor-challenge', ['code' => codiceA($this->segreto)])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('Coda 259 — il limite per utente della sfida dura cinque minuti', function () {
    foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5'] as $indirizzo) {
        $this->withServerVariables(['REMOTE_ADDR' => $indirizzo])
            ->withSession(sessioneDellaSfida($this->conDueFattori))
            ->post('/two-factor-challenge', ['code' => codiceSbagliato($this->segreto)]);
    }

    $this->travel(4)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.6'])
        ->withSession(sessioneDellaSfida($this->conDueFattori))
        ->post('/two-factor-challenge', ['code' => codiceA($this->segreto)])
        ->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->travel(2)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.6'])
        ->withSession(sessioneDellaSfida($this->conDueFattori))
        ->post('/two-factor-challenge', ['code' => codiceA($this->segreto)]);
    $this->assertAuthenticatedAs($this->conDueFattori);
});

it('Coda 259 — cinque codici sbagliati chiudono lo stato «password giusta»: si ricomincia dalla password', function () {
    $this->withSession(sessioneDellaSfida($this->conDueFattori));

    for ($i = 1; $i <= 4; $i++) {
        $this->post('/two-factor-challenge', ['code' => codiceSbagliato($this->segreto)])->assertSessionHasErrors('code');
        expect(session()->has('login.id'))->toBeTrue();
    }

    $this->post('/two-factor-challenge', ['code' => codiceSbagliato($this->segreto)])
        ->assertRedirect(route('login'))
        ->assertSessionHas('avviso', __('auth.two_factor_restart'));

    expect(session()->has('login.id'))->toBeFalse();

    // Senza lo stato, la pagina del codice non si apre più: rimanda al login.
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

it('Coda 259 — anche i codici di recupero sbagliati contano per chiudere lo stato', function () {
    $this->withSession(sessioneDellaSfida($this->conDueFattori));

    for ($i = 1; $i <= 5; $i++) {
        $this->post('/two-factor-challenge', ['recovery_code' => 'non-esiste-'.$i]);
    }

    expect(session()->has('login.id'))->toBeFalse();
});

it('Coda 259 — lo stato «password giusta» scade dopo dieci minuti', function () {
    $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    $this->travel(11)->minutes();

    $this->post('/two-factor-challenge', ['code' => codiceA($this->segreto)])
        ->assertRedirect(route('login'))
        ->assertSessionHas('avviso', __('auth.two_factor_expired'));

    $this->assertGuest();
    expect(session()->has('login.id'))->toBeFalse();
});

it('Coda 259 — dentro i dieci minuti lo stato vale', function () {
    $this->post('/login', ['email' => $this->conDueFattori->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    $this->travel(9)->minutes();

    $this->post('/two-factor-challenge', ['code' => codiceA($this->segreto)]);

    $this->assertAuthenticatedAs($this->conDueFattori);
});

it('Coda 259 — una sessione con lo stato ma senza l\'ora in cui è nato non vale', function () {
    // È lo stato lasciato da una versione precedente: non si sa quanto è vecchio.
    $this->withSession(['login.id' => $this->conDueFattori->id, 'login.remember' => false])
        ->post('/two-factor-challenge', ['code' => codiceA($this->segreto)])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('Coda 259 — un codice di un minuto fa non vale più', function () {
    $this->withSession(sessioneDellaSfida($this->conDueFattori))
        ->post('/two-factor-challenge', ['code' => codiceA($this->segreto, -2)])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('Coda 259 — il codice di trenta secondi fa vale ancora: la finestra è di ±30 secondi', function () {
    $this->withSession(sessioneDellaSfida($this->conDueFattori))
        ->post('/two-factor-challenge', ['code' => codiceA($this->segreto, -1)]);

    $this->assertAuthenticatedAs($this->conDueFattori);
});

it('Coda 259 — lo stesso codice non vale una seconda volta', function () {
    $codice = codiceA($this->segreto);

    $this->withSession(sessioneDellaSfida($this->conDueFattori))->post('/two-factor-challenge', ['code' => $codice]);
    $this->assertAuthenticatedAs($this->conDueFattori);

    $this->post('/logout');
    $this->assertGuest();

    $this->withSession(sessioneDellaSfida($this->conDueFattori))
        ->post('/two-factor-challenge', ['code' => $codice])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('Coda 259 — dopo un codice usato, nemmeno quello di prima vale', function () {
    $this->withSession(sessioneDellaSfida($this->conDueFattori))->post('/two-factor-challenge', ['code' => codiceA($this->segreto)]);
    $this->assertAuthenticatedAs($this->conDueFattori);
    $this->post('/logout');

    $this->withSession(sessioneDellaSfida($this->conDueFattori))
        ->post('/two-factor-challenge', ['code' => codiceA($this->segreto, -1)])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('Coda 259 — un codice di recupero vale una volta sola, come prima', function () {
    $this->withSession(sessioneDellaSfida($this->conDueFattori))->post('/two-factor-challenge', ['recovery_code' => 'recupero-uno-0001']);
    $this->assertAuthenticatedAs($this->conDueFattori);
    $this->post('/logout');

    $this->withSession(sessioneDellaSfida($this->conDueFattori))
        ->post('/two-factor-challenge', ['recovery_code' => 'recupero-uno-0001'])
        ->assertSessionHasErrors('recovery_code');

    $this->assertGuest();
    expect(json_decode(decrypt($this->conDueFattori->fresh()->two_factor_recovery_codes), true))->toBe(['recupero-due-0002']);
});

it('Coda 259 — la conferma della 2FA nelle impostazioni usa la stessa finestra', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($utente)->withSession(['auth.password_confirmed_at' => time()])->postJson(route('two-factor.enable'))->assertOk();
    $segreto = decrypt($utente->fresh()->two_factor_secret);

    $this->actingAs($utente)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('two-factor.confirm'), ['code' => codiceA($segreto, -2)])
        ->assertStatus(422);

    expect($utente->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('Coda 259 — il login ha un tetto di rotta con nome, sopra il limite del modulo', function () {
    // Il limite vero è nel modulo (errore sul campo); il tetto di rotta ferma chi insiste oltre.
    for ($i = 1; $i <= 60; $i++) {
        $this->post('/login', ['email' => "tentativo{$i}@example.com", 'password' => 'x'])->assertStatus(302);
    }

    $this->post('/login', ['email' => 'tentativo61@example.com', 'password' => 'x'])->assertStatus(429);
});

it('Coda 259 — la sfida ha un tetto di rotta con nome per indirizzo, sopra i limiti per utente', function () {
    // Sette utenti con la 2FA, nessuno oltre i suoi cinque tentativi: il trentunesimo codice dallo
    // stesso indirizzo lo ferma il tetto di rotta.
    $utenti = collect(range(1, 7))->map(fn () => User::factory()->create([
        'email_verified_at'       => now(),
        'two_factor_secret'       => encrypt($this->segreto),
        'two_factor_confirmed_at' => now(),
    ]));

    for ($i = 1; $i <= 30; $i++) {
        $this->withSession(sessioneDellaSfida($utenti[$i % 7]))
            ->post('/two-factor-challenge', ['code' => codiceSbagliato($this->segreto)])
            ->assertStatus(302);
    }

    $this->withSession(sessioneDellaSfida($utenti[31 % 7]))
        ->post('/two-factor-challenge', ['code' => codiceSbagliato($this->segreto)])
        ->assertStatus(429);
});

it('Coda 202 — il limite di un modulo per ospiti non consuma quello di un altro', function () {
    for ($i = 1; $i <= 6; $i++) {
        $this->post('/forgot-password', ['email' => "qualcuno{$i}@example.com"])->assertStatus(302);
    }

    // Lo stesso indirizzo, un modulo diverso: fino alla 1.11.0-beta.56 il contatore era uno solo.
    $this->post('/reset-password', ['token' => 'x', 'email' => 'qualcuno@example.com', 'password' => 'una-password-nuova', 'password_confirmation' => 'una-password-nuova'])
        ->assertStatus(302);

    // E il modulo di prima ha ancora il suo limite.
    $this->post('/forgot-password', ['email' => 'qualcuno7@example.com'])->assertStatus(429);
});

it('Coda 202 — per un utente collegato il limite di un modulo non consuma quello di un altro', function () {
    $utente = User::factory()->create(['email_verified_at' => null]);

    for ($i = 1; $i <= 6; $i++) {
        $this->actingAs($utente)->put('/settings/password', [
            'current_password'      => 'sbagliata',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ])->assertStatus(302);
    }

    $this->actingAs($utente)->post(route('verification.send'))->assertStatus(302);
});

it('Coda 202 — per un utente collegato il limite è suo: un altro utente dallo stesso indirizzo non lo consuma', function () {
    $primo = User::factory()->create(['email_verified_at' => now()]);
    $secondo = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 6; $i++) {
        $this->actingAs($primo)->put('/settings/password', [
            'current_password'      => 'sbagliata',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ])->assertStatus(302);
    }

    $this->actingAs($primo)->put('/settings/password', ['current_password' => 'sbagliata'])->assertStatus(429);

    $this->actingAs($secondo)->put('/settings/password', [
        'current_password'      => 'sbagliata',
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertStatus(302);
});

