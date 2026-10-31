<?php

/**
 * I link delle email del reset della password e della verifica dell'indirizzo portano l'indirizzo
 * configurato dell'installazione (`APP_URL`), qualunque sia l'host della richiesta che le fa partire.
 * Quando `APP_URL` è il valore di serie (vuoto, senza `http://` o `https://` davanti, o con host
 * `localhost`, `127.0.0.1` o `[::1]`) portano l'indirizzo della richiesta (`RadiceDeiLink`).
 *
 * Le richieste partono da un host diverso da quello configurato: con lo stesso host il confronto non
 * distingue la radice configurata da quella della richiesta. Il link si legge dalla mail spedita durante
 * la richiesta (il pulsante della notifica, nei dati di `MessageSent`), cioè da ciò che arriva alla casella.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Helpers\RedirectHelper;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use App\Settings\GeneralSettings;
use App\Support\RadiceDeiLink;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // `AppServiceProvider` forza `https` in console quando l'`APP_URL` con cui parte la suite è in `https`: le
    // richieste di questi test sono in `http`, e la radice della richiesta non dipende dal `.env`.
    URL::forceScheme(null);
});

/**
 * Il pulsante di ogni mail spedita da qui in avanti, per indirizzo del destinatario: l'`actionUrl` della
 * notifica, dai dati con cui la mail è stata composta.
 *
 * @return ArrayObject<string, string|null>
 */
function licPulsantiSpediti(): ArrayObject
{
    $pulsanti = new ArrayObject();

    Event::listen(MessageSent::class, function (MessageSent $spedita) use ($pulsanti) {
        foreach ($spedita->message->getTo() as $destinatario) {
            $pulsanti[$destinatario->getAddress()] = $spedita->data['actionUrl'] ?? null;
        }
    });

    return $pulsanti;
}

/** La registrazione pubblica accesa, con i ruoli e il permesso che la pagina d'arrivo legge. */
function licRegistrazionePubblicaAccesa(): void
{
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->user_frontend_registration = true;
    $impostazioni->save();

    Permission::firstOrCreate(['name' => PermissionEnum::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => RoleEnum::UTENTE->value, 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => RoleEnum::AMMINISTRATORE->value, 'guard_name' => 'web']);
}

/** Il modulo della registrazione pubblica, come lo manda la pagina. */
function licModuloDellaRegistrazione(string $email): array
{
    return [
        'name' => 'Nuovo Condomino',
        'email' => $email,
        'password' => 'password-nuova',
        'password_confirmation' => 'password-nuova',
    ];
}

/** I valori di `APP_URL` che non sono un indirizzo da mettere in una mail. */
function licIndirizziDiSerie(): array
{
    return [
        'vuoto' => [''],
        'localhost' => ['http://localhost'],
        'localhost con la porta' => ['http://localhost:8889'],
        'localhost in maiuscolo con la barra' => ['HTTPS://LOCALHOST/'],
        '127.0.0.1 con la porta' => ['http://127.0.0.1:8000'],
        '[::1]' => ['http://[::1]'],
        'senza schema' => ['gestionale.esempio.it'],
        'senza schema, con le due barre' => ['//gestionale.esempio.it'],
        'con una barra sola dopo lo schema' => ['http:/gestionale.esempio.it'],
    ];
}

// --- il reset della password -----------------------------------------------------------------------

it('il link del reset porta l\'indirizzo configurato, qualunque sia l\'host della richiesta, e cambia la password', function () {
    config(['app.url' => 'https://gestionale.esempio.it']);
    User::factory()->create(['email' => 'mario.rossi@example.com']);
    $pulsanti = licPulsantiSpediti();

    $this->post('http://host-falso.test/forgot-password', ['email' => 'mario.rossi@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // La radice è `APP_URL`; il percorso è quello di `password.reset` (`routes/auth.php`): il token
    // (sha256 in esadecimale, `DatabaseTokenRepository`) e l'email nella query.
    $link = (string) ($pulsanti['mario.rossi@example.com'] ?? '');
    expect($link)->toMatch('#^https://gestionale\.esempio\.it/reset-password/[0-9a-f]{64}\?email=mario\.rossi%40example\.com$#');

    // Il link apre il modulo, e il token che porta cambia la password.
    $this->get($link)->assertOk();

    preg_match('#/reset-password/([0-9a-f]{64})\?#', $link, $parti);
    $this->post('https://gestionale.esempio.it/reset-password', [
        'token' => $parti[1],
        'email' => 'mario.rossi@example.com',
        'password' => 'password-nuova',
        'password_confirmation' => 'password-nuova',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));
});

it('il link del reset porta l\'indirizzo configurato anche quando un proxy fidato dichiara un altro host', function () {
    config(['app.url' => 'https://gestionale.esempio.it', 'trustedproxy.proxies' => '*']);
    User::factory()->create(['email' => 'mario.rossi@example.com']);
    $pulsanti = licPulsantiSpediti();

    $this->withHeaders(['X-Forwarded-Host' => 'host-falso.test'])
        ->post('http://localhost/forgot-password', ['email' => 'mario.rossi@example.com'])
        ->assertSessionHasNoErrors();

    expect((string) ($pulsanti['mario.rossi@example.com'] ?? ''))
        ->toMatch('#^https://gestionale\.esempio\.it/reset-password/[0-9a-f]{64}\?email=mario\.rossi%40example\.com$#');
});

it('con APP_URL al valore di serie il link del reset porta l\'indirizzo della richiesta', function (string $diSerie) {
    config(['app.url' => $diSerie]);
    User::factory()->create(['email' => 'mario.rossi@example.com']);
    $pulsanti = licPulsantiSpediti();

    $this->post('http://host-falso.test/forgot-password', ['email' => 'mario.rossi@example.com'])
        ->assertSessionHasNoErrors();

    expect((string) ($pulsanti['mario.rossi@example.com'] ?? ''))
        ->toMatch('#^http://host-falso\.test/reset-password/[0-9a-f]{64}\?email=mario\.rossi%40example\.com$#');
})->with(licIndirizziDiSerie());

// --- la verifica dell'indirizzo --------------------------------------------------------------------

it('il link di verifica della registrazione pubblica porta l\'indirizzo configurato e verifica l\'account su quell\'indirizzo', function () {
    config(['app.url' => 'https://gestionale.esempio.it']);
    licRegistrazionePubblicaAccesa();
    $pulsanti = licPulsantiSpediti();

    $this->post('http://host-falso.test/register', licModuloDellaRegistrazione('nuovo.condomino@example.com'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('verification.notice', absolute: false));

    $utente = User::where('email', 'nuovo.condomino@example.com')->firstOrFail();
    expect($utente->hasVerifiedEmail())->toBeFalse();

    // La radice è `APP_URL`; il percorso è quello di `verification.verify` (`routes/auth.php`): l'id e lo
    // sha1 dell'email, poi la scadenza e la firma.
    $link = (string) ($pulsanti['nuovo.condomino@example.com'] ?? '');
    expect($link)->toStartWith('https://gestionale.esempio.it/verify-email/'.$utente->id.'/'.sha1('nuovo.condomino@example.com').'?expires=')
        ->and($link)->toContain('&signature=');

    $this->actingAs($utente)->get($link)
        ->assertRedirect(RedirectHelper::userHomeRoute().'?verified=1');

    expect($utente->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('con APP_URL al valore di serie il link di verifica porta l\'indirizzo della richiesta e verifica l\'account', function () {
    config(['app.url' => 'http://localhost']);
    licRegistrazionePubblicaAccesa();
    $pulsanti = licPulsantiSpediti();

    $this->post('http://host-falso.test/register', licModuloDellaRegistrazione('nuovo.condomino@example.com'))
        ->assertSessionHasNoErrors();

    $utente = User::where('email', 'nuovo.condomino@example.com')->firstOrFail();
    $link = (string) ($pulsanti['nuovo.condomino@example.com'] ?? '');
    expect($link)->toStartWith('http://host-falso.test/verify-email/'.$utente->id.'/'.sha1('nuovo.condomino@example.com').'?expires=');

    $this->actingAs($utente)->get($link)
        ->assertRedirect(RedirectHelper::userHomeRoute().'?verified=1');

    expect($utente->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('il link di verifica chiesto di nuovo dalla propria sessione porta l\'indirizzo configurato', function () {
    config(['app.url' => 'https://gestionale.esempio.it']);
    $utente = User::factory()->unverified()->create(['email' => 'luisa.bianchi@example.com']);
    $pulsanti = licPulsantiSpediti();

    $this->actingAs($utente)
        ->post('http://host-falso.test/email/verification-notification')
        ->assertSessionHasNoErrors();

    expect((string) ($pulsanti['luisa.bianchi@example.com'] ?? ''))
        ->toStartWith('https://gestionale.esempio.it/verify-email/'.$utente->id.'/'.sha1('luisa.bianchi@example.com').'?expires=');
});

it('il link di verifica che parte con la password del primo accesso porta l\'indirizzo configurato', function () {
    config(['app.url' => 'https://gestionale.esempio.it']);
    $utente = User::factory()->create(['email' => 'anna.verdi@example.com', 'password' => null, 'email_verified_at' => null]);
    $pulsanti = licPulsantiSpediti();

    // Il link del primo accesso firmato su un altro host: la firma lo lega a quell'host, e la richiesta
    // arriva da lì.
    app('url')->setRequest(Request::create('http://host-falso.test/'));
    $primoAccesso = NewUserEmailNotification::link($utente);

    $this->post($primoAccesso, [
        'email' => 'anna.verdi@example.com',
        'password' => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));

    expect((string) ($pulsanti['anna.verdi@example.com'] ?? ''))
        ->toStartWith('https://gestionale.esempio.it/verify-email/'.$utente->id.'/'.sha1('anna.verdi@example.com').'?expires=');
});

it('il link di verifica vale con la firma del suo percorso: una firma dell\'indirizzo intero non verifica l\'account', function () {
    $utente = User::factory()->unverified()->create();
    Permission::firstOrCreate(['name' => PermissionEnum::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web']);

    $indirizzoIntero = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $utente->id, 'hash' => sha1($utente->email)]
    );

    $this->actingAs($utente)->get($indirizzoIntero)->assertForbidden();

    expect($utente->fresh()->hasVerifiedEmail())->toBeFalse();
});

// --- la radice dei link ----------------------------------------------------------------------------

it('la radice dei link è APP_URL senza la barra finale, con la sua sottocartella e la sua porta', function (string $configurato, string $atteso) {
    config(['app.url' => $configurato]);
    app('url')->setRequest(Request::create('http://host-falso.test/qualunque'));

    expect(RadiceDeiLink::url('/reset-password/abc?email=a%40b.it'))->toBe($atteso);
})->with([
    'con la barra finale' => ['https://gestionale.esempio.it/', 'https://gestionale.esempio.it/reset-password/abc?email=a%40b.it'],
    'in una sottocartella' => ['https://esempio.it/condominio/', 'https://esempio.it/condominio/reset-password/abc?email=a%40b.it'],
    'in http' => ['http://gestionale.esempio.it', 'http://gestionale.esempio.it/reset-password/abc?email=a%40b.it'],
    'con la porta' => ['https://gestionale.esempio.it:8443', 'https://gestionale.esempio.it:8443/reset-password/abc?email=a%40b.it'],
    'con lo schema in maiuscolo' => ['HTTPS://GESTIONALE.ESEMPIO.IT', 'HTTPS://GESTIONALE.ESEMPIO.IT/reset-password/abc?email=a%40b.it'],
    'su un indirizzo di rete' => ['http://192.168.1.20', 'http://192.168.1.20/reset-password/abc?email=a%40b.it'],
]);

it('con APP_URL al valore di serie la radice dei link è quella della richiesta', function (string $diSerie) {
    config(['app.url' => $diSerie]);
    app('url')->setRequest(Request::create('http://host-falso.test/qualunque'));

    expect(RadiceDeiLink::url('/reset-password/abc?email=a%40b.it'))
        ->toBe('http://host-falso.test/reset-password/abc?email=a%40b.it');
})->with(licIndirizziDiSerie());
