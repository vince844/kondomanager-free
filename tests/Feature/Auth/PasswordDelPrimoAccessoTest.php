<?php

/**
 * La password del primo accesso si imposta solo con il link che l'ha mandata.
 *
 * ## Perché questo file esiste
 *
 * Un utente creato dall'amministratore riceve un'email con un link firmato a `GET /password/new`,
 * che mostra il modulo. Il modulo salvava con `POST /password/new`, e quella rotta **non chiedeva
 * niente**: nessuna firma, nessun token, nessun limite di tentativi. Il controller caricava l'utente
 * dall'email scritta nel corpo e gli scriveva la password nuova, anche se ne aveva già una. Bastava
 * conoscere l'email di qualcuno, amministratore compreso, e il token CSRF che dà qualunque pagina
 * pubblica. Così dal commit `972a6e7e` del 16/03/2025, in tutte le versioni dalla 1.0.0 alla 1.10.0.
 *
 * Il `POST` ora porta la stessa prova del `GET`: si manda all'URL firmato da cui è stata aperta la
 * pagina, l'utente si carica dall'`id` di quell'URL e non dall'email, e se la password c'è già non
 * si cambia. Il link porta anche l'impronta dell'account com'era quando è partito
 * (`User::improntaPrimoAccesso()`): dopo una correzione dell'email o un «reinvia» quello di prima non
 * vale più. A un link che non vale più si risponde rimandando al login con il messaggio.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il reset della password dimenticata (`/reset-password`), che ha il suo token salvato e
 * i suoi test in `PasswordResetTest.php`, né la registrazione da invito, in
 * `RegistrazioneDaInvitoTest.php`.
 */

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    // Come lo crea `UserService::createUser()`: senza password, in attesa del link.
    $this->nuovo = User::factory()->create(['password' => null, 'email_verified_at' => now()]);
    $this->nuovo->assignRole(RoleEnum::UTENTE->value);
});

/** Il link che parte con l'email del primo accesso, con una scadenza a scelta. */
function linkDelPrimoAccesso(User $utente, ?Carbon $scadenza = null): string
{
    return NewUserEmailNotification::link($utente, $scadenza);
}

/** Il link come parte davvero: quello della notifica, con tutti i suoi parametri. */
function linkDallaMail(User $utente): string
{
    return (new NewUserEmailNotification($utente))->toMail($utente)->actionUrl;
}

function passwordNuova(array $altro = []): array
{
    return $altro + [
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ];
}

it('chi conosce solo l\'email di un amministratore non gli cambia la password', function () {
    $this->post('/password/new', passwordNuova(['email' => $this->amministratore->email]));

    expect(Hash::check('password', $this->amministratore->fresh()->password))->toBeTrue();

    $this->post('/login', ['email' => $this->amministratore->email, 'password' => 'una-password-nuova']);
    $this->assertGuest();
});

it('chi conosce solo l\'email di un utente appena creato non gli sceglie la password', function () {
    $this->post('/password/new', passwordNuova(['email' => $this->nuovo->email]));

    expect($this->nuovo->fresh()->password)->toBeNull();
});

it('il link firmato imposta la password di chi lo ha ricevuto', function () {
    $this->post(linkDelPrimoAccesso($this->nuovo), passwordNuova(['email' => $this->nuovo->email]))
        ->assertRedirect(route('login'));

    expect(Hash::check('una-password-nuova', $this->nuovo->fresh()->password))->toBeTrue();
});

it('con il link di un utente e l\'email di un altro, cambia solo la password di chi ha il link', function () {
    $this->post(linkDelPrimoAccesso($this->nuovo), passwordNuova(['email' => $this->amministratore->email]));

    expect(Hash::check('password', $this->amministratore->fresh()->password))->toBeTrue();
    expect(Hash::check('una-password-nuova', $this->nuovo->fresh()->password))->toBeTrue();
});

it('il link non cambia una password che c\'è già', function () {
    $this->post(linkDelPrimoAccesso($this->amministratore), passwordNuova(['email' => $this->amministratore->email]))
        ->assertRedirect(route('login'));

    expect(Hash::check('password', $this->amministratore->fresh()->password))->toBeTrue();
});

it('un link scaduto non imposta niente', function () {
    $this->post(
        linkDelPrimoAccesso($this->nuovo, now()->subMinute()),
        passwordNuova(['email' => $this->nuovo->email])
    )->assertForbidden();

    expect($this->nuovo->fresh()->password)->toBeNull();
});

it('un link con l\'id cambiato a mano non vale', function () {
    $altro = User::factory()->create(['password' => null]);

    $manomesso = str_replace('id='.$this->nuovo->id, 'id='.$altro->id, linkDelPrimoAccesso($this->nuovo));

    $this->post($manomesso, passwordNuova(['email' => $altro->email]))->assertForbidden();

    expect($altro->fresh()->password)->toBeNull();
});

it('dopo sei tentativi in un minuto la rotta si ferma', function () {
    $link = linkDelPrimoAccesso($this->nuovo);
    $sbagliata = ['password' => 'una-password-nuova', 'password_confirmation' => 'non-coincide'];

    foreach (range(1, 6) as $tentativo) {
        $this->post($link, $sbagliata)->assertSessionHasErrors('password');
    }

    $this->post($link, $sbagliata)->assertStatus(429);
});

it('il link della mail apre il modulo, il modulo salva allo stesso URL, e il login lo conferma', function () {
    $link = linkDallaMail($this->nuovo);

    $azione = $this->get($link)->assertOk()->viewData('page')['props']['azione'];
    expect($azione)->toBe(parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY));

    $this->post($azione, passwordNuova(['email' => $this->nuovo->email]))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('notifications.new_user_created.password_set'));

    expect(Hash::check('una-password-nuova', $this->nuovo->fresh()->password))->toBeTrue();
});

it('dopo una correzione dell\'email, il link partito prima non imposta più niente', function () {
    // Il caso dell'indirizzo sbagliato: il link arriva a uno sconosciuto, l'amministratore corregge
    // l'email, e lo sconosciuto non deve poter scegliere la password dell'account corretto.
    $link = linkDallaMail($this->nuovo);
    $this->travel(1)->minutes();
    $this->nuovo->update(['email' => 'indirizzo-corretto@example.com']);

    $this->get($link)->assertRedirect(route('login'));
    $this->post($link, passwordNuova())
        ->assertRedirect(route('login'))
        ->assertSessionHas('avviso', __('notifications.new_user_created.link_expired'));

    expect($this->nuovo->fresh()->password)->toBeNull();
});

it('dopo un «reinvia», il link di prima non vale più', function () {
    $vecchio = linkDallaMail($this->nuovo);
    $this->post($vecchio, passwordNuova())->assertRedirect(route('login'));

    // «Reinvia» rimette la password a null: il link di prima tornerebbe buono se non portasse
    // l'impronta dello stato in cui è partito.
    $this->travel(1)->minutes();
    $this->actingAs($this->amministratore)->post(route('utenti.reinvite', $this->nuovo->email));
    $this->app['auth']->forgetGuards();
    expect($this->nuovo->fresh()->password)->toBeNull();

    $this->post($vecchio, passwordNuova(['password' => 'altra-password-1', 'password_confirmation' => 'altra-password-1']));

    expect($this->nuovo->fresh()->password)->toBeNull();
});

it('dietro un proxy che toglie un prefisso, il modulo si manda al percorso con il prefisso', function () {
    config(['trustedproxy.proxies' => '127.0.0.1']);
    URL::forceRootUrl(rtrim(config('app.url'), '/').'/km');
    $link = linkDallaMail($this->nuovo);
    URL::forceRootUrl(null);
    $query = parse_url($link, PHP_URL_QUERY);

    // Il proxy riceve /km/password/new, toglie il prefisso e lo dichiara nell'header.
    $azione = $this->get('/password/new?'.$query, ['X-Forwarded-Prefix' => '/km'])
        ->assertOk()->viewData('page')['props']['azione'];

    expect($azione)->toBe('/km/password/new?'.$query);
});

it('il registro dei link rifiutati non scrive la firma', function () {
    Log::spy();

    $this->get(linkDelPrimoAccesso($this->nuovo, now()->subMinute()))->assertForbidden();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($messaggio, $contesto = []) => $messaggio === '403 Invalid Signature'
            && ! str_contains(json_encode($contesto), 'signature='))
        ->once();
});

it('con un link scaduto il modulo torna al login con il messaggio, non con una finestra 403', function () {
    $this->post(linkDelPrimoAccesso($this->nuovo, now()->subMinute()), passwordNuova(), ['X-Inertia' => 'true'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('avviso', __('errors.403.invalid_signature'));

    expect($this->nuovo->fresh()->password)->toBeNull();
});

it('un «reinvia» su un utente che non ha ancora scelto la password rende morto anche il link di prima', function () {
    // Il caso della mail inoltrata per sbaglio: l'amministratore fa «reinvia» per togliere valore
    // al link che è andato in giro, e deve bastare anche se la password è ancora vuota.
    $vecchio = linkDallaMail($this->nuovo);

    $this->travel(1)->minutes();
    $this->actingAs($this->amministratore)->post(route('utenti.reinvite', $this->nuovo->email));
    $this->app['auth']->forgetGuards();

    $this->post($vecchio, passwordNuova())
        ->assertRedirect(route('login'))
        ->assertSessionHas('avviso', __('notifications.new_user_created.link_expired'));

    expect($this->nuovo->fresh()->password)->toBeNull();
});

it('con un altro utente collegato nel browser, il link del primo accesso non apre il modulo', function () {
    $link = linkDallaMail($this->nuovo);

    $this->actingAs($this->amministratore)->get($link)->assertRedirect(url('/'));
    $this->post($link, passwordNuova())->assertRedirect(url('/'));

    expect($this->nuovo->fresh()->password)->toBeNull();
});

it('il registro dei link rifiutati non scrive la firma nemmeno se il client di posta ha storpiato il link', function () {
    Log::spy();
    $link = linkDelPrimoAccesso($this->nuovo);
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    // Il client di posta che riscrive «&» come «&amp;»: la firma arriva sotto la chiave «amp;signature».
    $this->get(str_replace('&', '&amp;', $link))->assertForbidden();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($messaggio, $contesto = []) => $messaggio === '403 Invalid Signature'
            && ! str_contains(json_encode($contesto), $query['signature']))
        ->once();
});

it('i tentativi con una firma inventata contano nel limite', function () {
    foreach (range(1, 6) as $tentativo) {
        $this->post('/password/new?expires=9999999999&id='.$this->nuovo->id.'&signature=inventata', passwordNuova())
            ->assertForbidden();
    }

    $this->post('/password/new?expires=9999999999&id='.$this->nuovo->id.'&signature=inventata', passwordNuova())
        ->assertStatus(429);
});

it('il login mostra gli avvisi a parte dalle conferme', function () {
    $this->withSession(['avviso' => 'Un avviso di prova', 'status' => 'Una conferma di prova'])
        ->get(route('login'))
        ->assertInertia(fn ($pagina) => $pagina
            ->where('avviso', 'Un avviso di prova')
            ->where('status', 'Una conferma di prova'));
});
