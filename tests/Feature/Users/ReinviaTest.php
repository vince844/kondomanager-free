<?php

/**
 * «Reinvia» e i dispositivi dell'utente reinvitato.
 *
 * ## Perché questo file esiste
 *
 * «Reinvia» azzera la password dell'utente e gli manda un nuovo link del primo accesso: è il modo
 * con cui l'amministratore rimette un account nello stato di partenza, anche per chiudere fuori chi
 * lo stava usando. Il cookie «ricordami» porta il `remember_token`, e «reinvia» lo rinnova: il
 * cookie di prima non riconosce più nessuno, e il browser torna a essere quello di un ospite
 * (Coda 202, punto 1). Da Laravel 13.30 il framework confronta anche l'impronta della password
 * scritta nel cookie con quella dell'account: con il token di prima e la password vuota quel
 * confronto finirebbe in un errore 500 a ogni pagina di quel browser, compresi il login e il link
 * del primo accesso.
 *
 * Chi è stato reinvitato **prima** di questa versione ha ancora il token di prima. Per lui conta
 * `User::getAuthPassword()`, che per un account senza password restituisce una stringa vuota: il
 * confronto del cookie fallisce senza errore, e il browser torna ospite anche lì.
 *
 * Le sessioni già aperte le chiude `AutenticaSessione`, che prende il posto di `AuthenticateSession`
 * nel gruppo `web`: un account senza password è in attesa del primo accesso, e nessuna sua sessione
 * resta aperta. Vale anche per le sessioni nate prima di questa versione e per quella aperta da una
 * sfida 2FA cominciata prima di «reinvia». Chi ha una password segue la regola di prima (Coda 261).
 *
 * ## Cosa questo file NON copre
 *
 * Chi può reinvitare chi è in `PrivilegiUtentiTest.php` e in `PorteLateraliTest.php`; i link del primo
 * accesso in `tests/Feature/Auth/PasswordDelPrimoAccessoTest.php`; le sessioni chiuse dal cambio della
 * password in `tests/Feature/Auth/ConfermaPasswordESessioniTest.php`.
 */

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);
});

/** L'accesso del condòmino con «ricordami», poi «reinvia» dall'amministratore: il cookie che resta nel browser. */
function ricordamiPrimaDelReinvio($test): array
{
    $nome = Auth::guard('web')->getRecallerName();

    $accesso = $test->post('/login', ['email' => $test->condomino->email, 'password' => 'password', 'remember' => 'on']);
    $ricordami = $accesso->getCookie($nome)->getValue();

    $test->flushSession();
    Auth::forgetGuards();
    $test->actingAs($test->amministratore)
        ->post(route('utenti.reinvite', ['email' => $test->condomino->email]))
        ->assertRedirect(route('utenti.index'));

    $test->flushSession();
    Auth::forgetGuards();

    return [$nome, $ricordami];
}

/** Il link del primo accesso che «reinvia» ha appena mandato. */
function linkDelReinvio($test): string
{
    $link = null;
    Notification::assertSentTo($test->condomino, NewUserEmailNotification::class, function ($avviso) use ($test, &$link) {
        $link = $avviso->toMail($test->condomino->fresh())->actionUrl;

        return true;
    });

    return $link;
}

/**
 * Il condòmino entra con la password e apre una pagina (la sessione riceve l'impronta della password),
 * poi l'amministratore, da un altro dispositivo, fa «reinvia». Restituisce la sessione del condòmino
 * com'era prima di «reinvia»: è quella che il suo browser rimanda.
 */
function sessioneApertaPrimaDelReinvio($test): array
{
    $test->post('/login', ['email' => $test->condomino->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $test->get('/settings/password')->assertOk();
    $sessione = session()->all();

    $test->flushSession();
    Auth::forgetGuards();
    $test->actingAs($test->amministratore)
        ->post(route('utenti.reinvite', ['email' => $test->condomino->email]))
        ->assertRedirect(route('utenti.index'));

    $test->flushSession();
    Auth::forgetGuards();

    return $sessione;
}

/** Un reinvito fatto prima dell'aggiornamento: la password vuota, il `remember_token` di prima. */
function reinvioDiPrimaDellAggiornamento(User $utente): void
{
    $utente->update(['password' => null]);
    $utente->touch();
}

/** Il codice 2FA di adesso; negli ultimi tre secondi di un periodo aspetta il successivo. */
function codiceDueFattoriDiAdesso(string $segreto): string
{
    $secondo = fmod(microtime(true), 30);
    if ($secondo > 27) {
        usleep((int) ((30 - $secondo) * 1_000_000) + 100_000);
    }

    $g = new Google2FA;

    return $g->oathTotp($segreto, $g->getTimestamp());
}

it('Coda 202 — dopo «reinvia» il cookie «ricordami» di un altro dispositivo porta al login, non dentro né a un errore', function () {
    $tokenPrima = $this->condomino->fresh()->remember_token;
    [$nome, $ricordami] = ricordamiPrimaDelReinvio($this);

    expect($this->condomino->fresh()->password)->toBeNull()
        ->and($this->condomino->fresh()->remember_token)->not->toBe($tokenPrima);

    $this->withCookie($nome, $ricordami)->get('/settings/password')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('Coda 202 — dopo «reinvia» il link nuovo si apre e salva anche nel browser dove l\'utente aveva scelto «ricordami»', function () {
    [$nome, $ricordami] = ricordamiPrimaDelReinvio($this);
    $link = linkDelReinvio($this);

    $this->withCookie($nome, $ricordami)->get($link)->assertOk();

    $this->withCookie($nome, $ricordami)
        ->post($link, ['password' => 'una-password-nuova', 'password_confirmation' => 'una-password-nuova'])
        ->assertRedirect(route('login'));

    expect(Hash::check('una-password-nuova', $this->condomino->fresh()->password))->toBeTrue();
});

it('Coda 202 — dopo «reinvia» il link nuovo imposta la password, e con quella si entra', function () {
    $this->actingAs($this->amministratore)
        ->post(route('utenti.reinvite', ['email' => $this->condomino->email]))
        ->assertRedirect(route('utenti.index'));
    $link = linkDelReinvio($this);

    $this->flushSession();
    Auth::forgetGuards();
    $this->post($link, ['password' => 'una-password-nuova', 'password_confirmation' => 'una-password-nuova'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    $this->post('/login', ['email' => $this->condomino->email, 'password' => 'una-password-nuova'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->condomino->fresh());
});

// ─── Reinvitato prima di questa versione, con il cookie «ricordami» di prima ──────────────────

it('Coda 202 — chi è stato reinvitato prima dell\'aggiornamento e ha il cookie «ricordami» trova il login, non un errore', function () {
    $nome = Auth::guard('web')->getRecallerName();
    $accesso = $this->post('/login', ['email' => $this->condomino->email, 'password' => 'password', 'remember' => 'on']);
    $ricordami = $accesso->getCookie($nome)->getValue();
    $tokenPrima = $this->condomino->fresh()->remember_token;

    reinvioDiPrimaDellAggiornamento($this->condomino);
    expect($this->condomino->fresh()->password)->toBeNull()
        ->and($this->condomino->fresh()->remember_token)->toBe($tokenPrima);

    $this->flushSession();
    Auth::forgetGuards();
    $this->withCookie($nome, $ricordami)->get('/login')->assertOk();
    $this->assertGuest();

    $this->withCookie($nome, $ricordami)->get('/settings/password')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('Coda 202 — chi è stato reinvitato prima dell\'aggiornamento apre e salva il link anche nel browser con il cookie «ricordami» di prima', function () {
    $nome = Auth::guard('web')->getRecallerName();
    $accesso = $this->post('/login', ['email' => $this->condomino->email, 'password' => 'password', 'remember' => 'on']);
    $ricordami = $accesso->getCookie($nome)->getValue();

    reinvioDiPrimaDellAggiornamento($this->condomino);
    $link = NewUserEmailNotification::link($this->condomino->fresh());

    $this->flushSession();
    Auth::forgetGuards();
    $this->withCookie($nome, $ricordami)->get($link)->assertOk();

    $this->withCookie($nome, $ricordami)
        ->post($link, ['password' => 'una-password-nuova', 'password_confirmation' => 'una-password-nuova'])
        ->assertRedirect(route('login'));

    expect(Hash::check('una-password-nuova', $this->condomino->fresh()->password))->toBeTrue();
});

// ─── Le sessioni già aperte ─────────────────────────────────────────────────────────────────

it('Coda 202 — dopo «reinvia» la sessione già aperta del reinvitato porta al login', function () {
    $sessione = sessioneApertaPrimaDelReinvio($this);

    $this->withSession($sessione)->get('/settings/password')->assertRedirect(route('login'));
    $this->assertGuest();

    // La sessione è chiusa davvero: la richiesta dopo, con la sessione com'è rimasta, non riconosce più
    // nessuno.
    Auth::forgetGuards();
    $this->get('/settings/password')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('Coda 202 — dopo «reinvia» una richiesta JSON della sessione già aperta riceve 401', function () {
    $sessione = sessioneApertaPrimaDelReinvio($this);

    $this->withSession($sessione)->getJson('/settings/password')->assertUnauthorized();
    $this->assertGuest();
});

it('Coda 202 — dopo «reinvia» il link nuovo si apre e salva nel browser della sessione chiusa', function () {
    $sessione = sessioneApertaPrimaDelReinvio($this);
    $link = linkDelReinvio($this);

    // La prima richiesta di quel browser chiude la sessione di prima.
    $this->withSession($sessione)->get($link)->assertRedirect(route('login'));
    $this->assertGuest();

    Auth::forgetGuards();
    $this->get($link)->assertOk();
    $this->post($link, ['password' => 'una-password-nuova', 'password_confirmation' => 'una-password-nuova'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    expect(Hash::check('una-password-nuova', $this->condomino->fresh()->password))->toBeTrue();

    $this->post('/login', ['email' => $this->condomino->email, 'password' => 'una-password-nuova'])
        ->assertSessionHasNoErrors();
    $this->get('/settings/password')->assertOk();
    $this->assertAuthenticatedAs($this->condomino->fresh());
});

it('Coda 202 — una sessione del reinvitato nata prima di questa versione, senza l\'impronta della password, si chiude anche lei', function () {
    // Una sessione aperta prima che `AuthenticateSession` entrasse nel gruppo `web` non ha l'impronta della
    // password. Da Laravel 13.38 il login la scrive subito, quindi la sessione di prima si costruisce
    // togliendola a mano dopo l'accesso.
    $this->post('/login', ['email' => $this->condomino->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $sessione = session()->all();
    unset($sessione['password_hash_web']);
    expect($sessione)->not->toHaveKey('password_hash_web');

    reinvioDiPrimaDellAggiornamento($this->condomino);

    $this->flushSession();
    Auth::forgetGuards();
    $this->withSession($sessione)->get('/settings/password')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('Coda 202 — la sessione aperta da una sfida 2FA cominciata prima di «reinvia» si chiude alla richiesta dopo, anche con «ricordami»', function () {
    $segreto = (new Google2FA)->generateSecretKey();
    $this->condomino->forceFill([
        'two_factor_secret'         => encrypt($segreto),
        'two_factor_recovery_codes' => encrypt(json_encode(['recupero-uno-0001', 'recupero-due-0002'])),
        'two_factor_confirmed_at'   => now(),
    ])->save();
    $nome = Auth::guard('web')->getRecallerName();

    // La password giusta apre la sfida…
    $this->post('/login', ['email' => $this->condomino->email, 'password' => 'password', 'remember' => 'on'])
        ->assertRedirect(route('two-factor.challenge'));
    $sfida = session()->all();

    // …«reinvia» arriva prima del codice…
    $this->flushSession();
    Auth::forgetGuards();
    $this->actingAs($this->amministratore)
        ->post(route('utenti.reinvite', ['email' => $this->condomino->email]))
        ->assertRedirect(route('utenti.index'));
    $this->flushSession();
    Auth::forgetGuards();

    // …e il codice giusto fa entrare un account che non ha più una password.
    $codice = $this->withSession($sfida)->post('/two-factor-challenge', ['code' => codiceDueFattoriDiAdesso($segreto)]);
    $this->assertAuthenticatedAs($this->condomino->fresh());
    $ricordami = $codice->getCookie($nome)?->getValue();
    expect($ricordami)->not->toBeNull();

    Auth::forgetGuards();
    $this->get('/settings/password')->assertRedirect(route('login'));
    $this->assertGuest();

    // Il cookie «ricordami» nato da quella sfida non riapre niente.
    $this->flushSession();
    Auth::forgetGuards();
    $this->withCookie($nome, $ricordami)->get('/settings/password')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('Coda 202 — «reinvia» chiude solo le sessioni del reinvitato: chi reinvita e gli altri utenti restano dentro', function () {
    $vicino = User::factory()->create(['email_verified_at' => now()]);
    $vicino->assignRole(RoleEnum::UTENTE->value);
    $nome = Auth::guard('web')->getRecallerName();

    $accesso = $this->post('/login', ['email' => $vicino->email, 'password' => 'password', 'remember' => 'on']);
    $ricordamiDelVicino = $accesso->getCookie($nome)->getValue();
    $this->get('/settings/password')->assertOk();
    $sessioneDelVicino = session()->all();

    $this->flushSession();
    Auth::forgetGuards();
    $this->post('/login', ['email' => $this->amministratore->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->get(route('utenti.index'))->assertOk();
    $this->post(route('utenti.reinvite', ['email' => $this->condomino->email]))
        ->assertRedirect(route('utenti.index'));

    // Chi ha reinvitato continua a lavorare (la richiesta dopo ricarica l'utente dalla sessione).
    Auth::forgetGuards();
    $this->get(route('utenti.index'))->assertOk();
    $this->assertAuthenticatedAs($this->amministratore);

    // Un altro utente, con la sua sessione e con il suo cookie, resta dentro.
    $this->flushSession();
    Auth::forgetGuards();
    $this->withSession($sessioneDelVicino)->get('/settings/password')->assertOk();
    $this->assertAuthenticatedAs($vicino);

    $this->flushSession();
    Auth::forgetGuards();
    $this->withCookie($nome, $ricordamiDelVicino)->get('/settings/password')->assertOk();
    $this->assertAuthenticatedAs($vicino);
});
