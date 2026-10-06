<?php

/**
 * Ci si registra da un invito solo con il link dell'invito.
 *
 * ## Perché questo file esiste
 *
 * L'invito parte come email con un link firmato a `GET /invito/register`, che mostra il modulo. Il
 * modulo salvava con `POST /invito/register`, e quella rotta controllava solo che per l'email del
 * corpo esistesse un invito non scaduto e non accettato: non riceveva né la firma né l'id del link.
 * Chi conosceva o indovinava l'email di un invitato creava l'account con una password sua e bruciava
 * l'invito. I condomìni dell'invito no: si agganciano creando la propria anagrafica, che chiede
 * l'email verificata, e il link di verifica arriva all'invitato. Lo stesso faceva `POST /register`,
 * con la registrazione pubblica accesa, perché `UserRegistrationService` accettava l'invito con la
 * stessa email.
 *
 * Il `POST` ora porta la stessa prova del `GET`: si manda all'URL firmato da cui è stata aperta la
 * pagina, l'invito si carica dall'`id` di quell'URL, e l'email dell'account è quella dell'invito,
 * non quella del corpo. Un invito lo accetta solo il suo link, e solo un invito accettato dà i suoi
 * condomìni. Un invito già usato, scaduto, o per un'email che ha già un account rimanda al login con
 * un messaggio che dice cosa fare. L'invito vale tre giorni, quanto il link (`InvitiTest.php`).
 *
 * ## Cosa questo file NON copre
 *
 * Non copre chi può spedire, leggere o cancellare gli inviti (`InvitiTest.php`), né la password del
 * primo accesso di un utente creato dall'amministratore (`PasswordDelPrimoAccessoTest.php`).
 */

use App\Models\Condominio;
use App\Models\Invito;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->invito = Invito::create([
        'email'          => 'invitato@example.com',
        'building_codes' => ['ABC123'],
        'expires_at'     => now()->addDays(Invito::GIORNI_DI_VALIDITA),
    ]);
});

/** Il link come parte davvero: quello della notifica dell'invito. */
function linkDellInvito(Invito $invito): string
{
    return (new InviteUserNotification($invito))->toMail($invito)->actionUrl;
}

function datiDellaRegistrazione(string $email): array
{
    return [
        'name'                  => 'Chi si registra',
        'email'                 => $email,
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ];
}

it('chi conosce l\'email di un invito non si registra al posto dell\'invitato', function () {
    $this->post('/invito/register', datiDellaRegistrazione('invitato@example.com'));

    expect(User::where('email', 'invitato@example.com')->exists())->toBeFalse();
    expect($this->invito->fresh()->accepted_at)->toBeNull();
    $this->assertGuest();
});

it('il link firmato dell\'invito registra l\'invitato', function () {
    $this->post(linkDellInvito($this->invito), datiDellaRegistrazione('invitato@example.com'))
        ->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'invitato@example.com')->exists())->toBeTrue();
    expect($this->invito->fresh()->accepted_at)->not->toBeNull();
    $this->assertAuthenticated();
});

it('con il link di un invito e l\'email di un altro, l\'account ha l\'email dell\'invito', function () {
    $altro = Invito::create([
        'email'          => 'altro@example.com',
        'building_codes' => ['XYZ789'],
        'expires_at'     => now()->addDays(Invito::GIORNI_DI_VALIDITA),
    ]);

    $this->post(linkDellInvito($this->invito), datiDellaRegistrazione('altro@example.com'));

    expect(User::where('email', 'altro@example.com')->exists())->toBeFalse();
    expect($altro->fresh()->accepted_at)->toBeNull();
    expect(User::where('email', 'invitato@example.com')->exists())->toBeTrue();
});

it('un invito già accettato non si usa una seconda volta: il link rimanda al login', function () {
    // Com'è davvero: l'invito accettato ha il suo account, e il messaggio è «già usato», non
    // «esiste già un account».
    $this->invito->accept();
    User::factory()->create(['email' => 'invitato@example.com']);
    $link = linkDellInvito($this->invito);
    $messaggio = __('notifications.invite_user.already_used');

    $this->get($link)->assertRedirect(route('login'))->assertSessionHas('avviso', $messaggio);
    $this->post($link, datiDellaRegistrazione('invitato@example.com'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('avviso', $messaggio);

    expect(User::where('email', 'invitato@example.com')->count())->toBe(1);
});

it('un invito scaduto rimanda al login con il suo messaggio, anche dal link della mail', function () {
    // Il link della mail non scade da sé: la scadenza è dell'invito, e così chi lo apre il quarto
    // giorno legge «l'invito è scaduto, chiedine un altro» e non il 403 generico della firma.
    $link = linkDellInvito($this->invito);
    $this->travel(Invito::GIORNI_DI_VALIDITA)->days();
    $this->travel(1)->minutes();
    $messaggio = __('notifications.invite_user.expired');

    $this->get($link)->assertRedirect(route('login'))->assertSessionHas('avviso', $messaggio);
    $this->post($link, datiDellaRegistrazione('invitato@example.com'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('avviso', $messaggio);

    expect(User::where('email', 'invitato@example.com')->exists())->toBeFalse();
    expect($this->invito->fresh()->accepted_at)->toBeNull();
});

it('un invito di prima della 1.11.0-beta.45, scaduto dopo un\'ora con il link ancora valido, rimanda al login con il messaggio', function () {
    $this->invito->update(['expires_at' => now()->subMinute()]);
    $link = URL::temporarySignedRoute('invito.register', now()->addDays(3), ['id' => $this->invito->id]);

    $this->get($link)->assertRedirect(route('login'))
        ->assertSessionHas('avviso', __('notifications.invite_user.expired'));
});

it('a invito scaduto, chi ha già l\'account legge di accedere, non di chiedere un altro invito', function () {
    User::factory()->create(['email' => 'invitato@example.com']);
    $link = linkDellInvito($this->invito);
    $this->travel(Invito::GIORNI_DI_VALIDITA + 1)->days();

    $this->get($link)->assertRedirect(route('login'))
        ->assertSessionHas('avviso', __('notifications.invite_user.account_exists'));
});

it('se esiste già un account con l\'email dell\'invito, il link rimanda al login con la strada da seguire', function () {
    User::factory()->create(['email' => 'invitato@example.com']);
    $link = linkDellInvito($this->invito);
    $messaggio = __('notifications.invite_user.account_exists');

    $this->get($link)->assertRedirect(route('login'))->assertSessionHas('avviso', $messaggio);
    $this->post($link, datiDellaRegistrazione('invitato@example.com'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('avviso', $messaggio);

    expect(User::where('email', 'invitato@example.com')->count())->toBe(1);
});

it('un invito scritto con le maiuscole si completa, e l\'account ha l\'email in minuscolo', function () {
    $invito = Invito::create([
        'email'          => 'Mario.Rossi@Example.it',
        'building_codes' => [],
        'expires_at'     => now()->addDays(Invito::GIORNI_DI_VALIDITA),
    ]);

    $this->post(linkDellInvito($invito), datiDellaRegistrazione('Mario.Rossi@Example.it'))
        ->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'mario.rossi@example.it')->exists())->toBeTrue();
    expect($invito->fresh()->accepted_at)->not->toBeNull();
});

it('con un altro utente collegato nel browser, il link dell\'invito non apre il modulo', function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get(linkDellInvito($this->invito))
        ->assertRedirect(url('/'));
});

it('dopo sei tentativi in un minuto la rotta si ferma', function () {
    // Il limite è contro lo spam, non contro chi indovina: qui non c'è niente da indovinare, la
    // prova è la firma del link.
    $link = linkDellInvito($this->invito);
    $sbagliata = ['password_confirmation' => 'non-coincide'] + datiDellaRegistrazione('invitato@example.com');

    foreach (range(1, 6) as $tentativo) {
        $this->post($link, $sbagliata)->assertSessionHasErrors('password');
    }

    $this->post($link, $sbagliata)->assertStatus(429);
});

it('l\'invito accettato con il suo link dà i suoi condomìni all\'anagrafica', function () {
    $condominio = Condominio::factory()->create(['codice_identificativo' => 'ABC123']);

    $this->post(linkDellInvito($this->invito), datiDellaRegistrazione('invitato@example.com'));
    $utente = User::where('email', 'invitato@example.com')->firstOrFail();
    $utente->markEmailAsVerified();

    $this->actingAs($utente)->post(route('user.anagrafiche.store'), ['nome' => 'Chi si registra', 'indirizzo' => 'Via Roma 1']);

    expect($utente->fresh()->anagrafica->condomini->pluck('id')->all())->toBe([$condominio->id]);
});

it('un link con l\'id cambiato a mano non vale', function () {
    $altro = Invito::create([
        'email'          => 'altro@example.com',
        'building_codes' => ['XYZ789'],
        'expires_at'     => now()->addDays(Invito::GIORNI_DI_VALIDITA),
    ]);

    $manomesso = str_replace('id='.$this->invito->id, 'id='.$altro->id, linkDellInvito($this->invito));

    $this->post($manomesso, datiDellaRegistrazione('altro@example.com'))->assertForbidden();

    expect(User::where('email', 'altro@example.com')->exists())->toBeFalse();
});

it('la pagina aperta dal link manda il modulo allo stesso URL firmato, e da lì ci si registra', function () {
    $link = linkDellInvito($this->invito);
    $pagina = $this->get($link)->assertOk();

    $azione = $pagina->viewData('page')['props']['azione'];
    expect($azione)->toBe(parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY));

    $this->post($azione, datiDellaRegistrazione('invitato@example.com'))
        ->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'invitato@example.com')->exists())->toBeTrue();
});

it('con la registrazione pubblica accesa, registrarsi con l\'email di un invito non lo consuma e non dà i suoi condomìni', function () {
    $condominio = Condominio::factory()->create(['codice_identificativo' => 'ABC123']);
    app(\App\Settings\GeneralSettings::class)->user_frontend_registration = true;
    app(\App\Settings\GeneralSettings::class)->save();

    $this->post('/register', datiDellaRegistrazione('invitato@example.com'));

    // L'account nasce, perché la registrazione pubblica è aperta a chiunque, con l'email da
    // verificare. L'invito no: lo accetta solo il suo link. Al vero invitato, che trova l'email già
    // presa, il link dice di accedere o di recuperare la password (test qui sopra).
    $utente = User::where('email', 'invitato@example.com')->firstOrFail();
    expect($this->invito->fresh()->accepted_at)->toBeNull();

    // E nemmeno con l'email verificata l'anagrafica prende i condomìni di un invito che il suo link
    // non ha accettato.
    $utente->markEmailAsVerified();
    $this->actingAs($utente)->post(route('user.anagrafiche.store'), ['nome' => 'Chi si registra', 'indirizzo' => 'Via Roma 1']);

    expect($utente->fresh()->anagrafica)->not->toBeNull();
    expect($utente->fresh()->anagrafica->condomini)->toHaveCount(0);
    expect($condominio->fresh())->not->toBeNull();
});

it('i tentativi con una firma inventata contano nel limite', function () {
    $dati = datiDellaRegistrazione('invitato@example.com');

    foreach (range(1, 6) as $tentativo) {
        $this->post('/invito/register?id='.$this->invito->id.'&signature=inventata', $dati)->assertForbidden();
    }

    $this->post('/invito/register?id='.$this->invito->id.'&signature=inventata', $dati)->assertStatus(429);
});
