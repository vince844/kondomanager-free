<?php

/**
 * Coda 267 — il titolare di un account riceve un avviso quando cambiano la password, l'email o la
 * 2FA.
 *
 * ## Perché questo file esiste
 *
 * Quando la sicurezza di un account cambia, il titolare riceve un'email: così sa anche di un cambio
 * che non ha fatto lui. La decisione 126.1 vuole l'avviso per la password
 * cambiata dal profilo o reimpostata dal link «password dimenticata», per l'email cambiata (al vecchio
 * indirizzo, con il nuovo mascherato) e per la 2FA attivata, disattivata o con i codici di recupero
 * rigenerati. Non per la prima password scelta dal link dell'invito o del primo accesso: non c'è
 * niente di prima da proteggere, e un avviso a chi ha appena ricevuto il link non gli direbbe niente
 * di nuovo.
 *
 * Nell'email: data e ora, e chi ha fatto la modifica quando non è il titolare; niente indirizzo IP.
 * Se l'invio fallisce l'azione riesce lo stesso e l'errore va nel registro. Le preferenze di notifica
 * non li spengono: filtrano i destinatari degli avvisi del portale (`FilterByNotificationPreference`),
 * e questi avvisi non passano di lì.
 *
 * ## Cosa questo file NON copre
 *
 * L'avviso di accesso da un dispositivo nuovo (Coda 385). La conferma della password sulle rotte della
 * 2FA (Coda 261): qui la sessione la porta già confermata.
 */

use App\Enums\Role as RoleEnum;
use App\Models\Invito;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Notifications\NewUserEmailNotification;
use App\Notifications\Users\AvvisoDiSicurezzaNotification;
use App\Services\Notifiche\AvvisiDiSicurezza;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->titolare = User::factory()->create([
        'name'              => 'Maria Titolare',
        'email'             => 'maria@example.com',
        'email_verified_at' => now(),
    ]);
    $this->titolare->assignRole(RoleEnum::UTENTE->value);
});

/** Il testo dell'email come la riceve chi la legge: oggetto e righe. */
function testoDellAvviso(AvvisoDiSicurezzaNotification $avviso, object $destinatario): string
{
    $mail = $avviso->toMail($destinatario);

    return implode("\n", array_merge([$mail->subject, $mail->greeting], $mail->introLines, $mail->outroLines));
}

/** La sessione con la password appena confermata: le rotte della 2FA la chiedono (Coda 261). */
function confermata(): array
{
    return ['auth.password_confirmed_at' => time()];
}

it('Coda 267 — il cambio della password dal profilo avvisa il titolare', function () {
    $this->actingAs($this->titolare)
        ->put('/settings/password', [
            'current_password'      => 'password',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->titolare, AvvisoDiSicurezzaNotification::class,
        fn (AvvisoDiSicurezzaNotification $avviso) => $avviso->evento === AvvisiDiSicurezza::PASSWORD_CAMBIATA);
});

it('Coda 267 — la password reimpostata dal link «password dimenticata» avvisa il titolare', function () {
    $token = Password::broker()->createToken($this->titolare);

    $this->post('/reset-password', [
        'token'                 => $token,
        'email'                 => $this->titolare->email,
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo($this->titolare, AvvisoDiSicurezzaNotification::class,
        fn (AvvisoDiSicurezzaNotification $avviso) => $avviso->evento === AvvisiDiSicurezza::PASSWORD_REIMPOSTATA);
});

it('Coda 267 — l\'email cambiata dal profilo avvisa il vecchio indirizzo, con il nuovo mascherato', function () {
    $this->actingAs($this->titolare)
        ->patch('/settings/profile', [
            'name'             => $this->titolare->name,
            'email'            => 'nuovo.indirizzo@altrove.it',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(AvvisoDiSicurezzaNotification::class,
        function (AvvisoDiSicurezzaNotification $avviso, array $canali, AnonymousNotifiable $destinatario) {
            $testo = testoDellAvviso($avviso, $destinatario);

            return $destinatario->routes['mail'] === 'maria@example.com'
                && $avviso->evento === AvvisiDiSicurezza::EMAIL_CAMBIATA
                && str_contains($testo, 'n***@altrove.it')
                && ! str_contains($testo, 'nuovo.indirizzo@altrove.it');
        });

    // Il nuovo indirizzo non riceve questo avviso: riceve, se mai, la verifica.
    Notification::assertNotSentTo($this->titolare->fresh(), AvvisoDiSicurezzaNotification::class);
});

it('Coda 267 — salvare il profilo senza cambiare l\'email non avvisa nessuno', function () {
    $this->actingAs($this->titolare)
        ->patch('/settings/profile', ['name' => 'Maria Nuovo Nome', 'email' => $this->titolare->email])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(AvvisoDiSicurezzaNotification::class, 0);
    Notification::assertNotSentTo($this->titolare, AvvisoDiSicurezzaNotification::class);
    expect($this->titolare->fresh()->name)->toBe('Maria Nuovo Nome');
});

it('Coda 267 — l\'email cambiata da un amministratore dalla scheda utenti avvisa il vecchio indirizzo e dice chi è stato', function () {
    $amministratore = User::factory()->create(['name' => 'Anna Amministratrice', 'email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->actingAs($amministratore)
        ->put(route('utenti.update', ['utenti' => $this->titolare->id]), [
            'name'        => $this->titolare->name,
            'email'       => 'maria.nuova@example.com',
            'roles'       => $this->titolare->roles->first()->id,
            'permissions' => [],
            'anagrafica'  => null,
        ])
        ->assertSessionHasNoErrors();

    expect($this->titolare->fresh()->email)->toBe('maria.nuova@example.com');

    Notification::assertSentOnDemand(AvvisoDiSicurezzaNotification::class,
        function (AvvisoDiSicurezzaNotification $avviso, array $canali, AnonymousNotifiable $destinatario) {
            $testo = testoDellAvviso($avviso, $destinatario);

            return $destinatario->routes['mail'] === 'maria@example.com'
                && str_contains($testo, 'Anna Amministratrice')
                && str_contains($testo, 'm***@example.com')
                && ! str_contains($testo, 'maria.nuova@example.com');
        });
});

// La propria email si cambia solo dal profilo, con la password attuale (Coda 261): la scheda utenti rifiuta il
// cambio della propria email senza mandare nessun avviso, quindi dalla scheda l'autore di un cambio d'email non
// è mai il titolare. La regola dell'avviso (l'autore si nomina solo se non è il titolare) resta, e si prova sul
// servizio che la applica per tutte le strade.
it('Coda 267 — l\'amministratore non cambia la propria email dalla scheda utenti, e non parte nessun avviso', function () {
    $amministratore = User::factory()->create(['name' => 'Anna Amministratrice', 'email' => 'anna@example.com', 'email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->actingAs($amministratore)
        ->put(route('utenti.update', ['utenti' => $amministratore->id]), [
            'name'        => $amministratore->name,
            'email'       => 'anna.nuova@example.com',
            'roles'       => $amministratore->roles->first()->id,
            'permissions' => [],
            'anagrafica'  => null,
        ])
        ->assertSessionHasErrors('email');

    expect($amministratore->fresh()->email)->toBe('anna@example.com');
    Notification::assertSentOnDemandTimes(AvvisoDiSicurezzaNotification::class, 0);
});

it('Coda 267 — l\'avviso dell\'email cambiata non nomina l\'autore quando è il titolare', function () {
    $amministratore = User::factory()->create(['name' => 'Anna Amministratrice', 'email' => 'anna.nuova@example.com', 'email_verified_at' => now()]);

    app(AvvisiDiSicurezza::class)->emailCambiata($amministratore, 'anna@example.com', $amministratore);

    Notification::assertSentOnDemand(AvvisoDiSicurezzaNotification::class,
        fn (AvvisoDiSicurezzaNotification $avviso, array $canali, AnonymousNotifiable $destinatario) => $destinatario->routes['mail'] === 'anna@example.com'
            && $avviso->autore === null);
});

it('Coda 267 — la 2FA attivata avvisa il titolare', function () {
    $this->actingAs($this->titolare)->withSession(confermata())->postJson(route('two-factor.enable'))->assertOk();
    $segreto = decrypt($this->titolare->fresh()->two_factor_secret);

    $this->actingAs($this->titolare)->withSession(confermata())
        ->postJson(route('two-factor.confirm'), ['code' => (new Google2FA)->getCurrentOtp($segreto)])
        ->assertOk();

    Notification::assertSentTo($this->titolare, AvvisoDiSicurezzaNotification::class,
        fn (AvvisoDiSicurezzaNotification $avviso) => $avviso->evento === AvvisiDiSicurezza::DUE_FATTORI_ATTIVATA);
});

it('Coda 267 — un codice sbagliato alla conferma non attiva niente e non avvisa', function () {
    $this->actingAs($this->titolare)->withSession(confermata())->postJson(route('two-factor.enable'))->assertOk();

    $this->actingAs($this->titolare)->withSession(confermata())
        ->postJson(route('two-factor.confirm'), ['code' => '000000'])
        ->assertStatus(422);

    Notification::assertNotSentTo($this->titolare, AvvisoDiSicurezzaNotification::class);
});

it('Coda 267 — la 2FA disattivata avvisa il titolare', function () {
    $this->titolare->forceFill([
        'two_factor_secret'         => encrypt((new Google2FA)->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode(['codice-uno'])),
        'two_factor_confirmed_at'   => now(),
    ])->save();

    $this->actingAs($this->titolare)->withSession(confermata())
        ->deleteJson(route('two-factor.disable'))
        ->assertSuccessful();

    Notification::assertSentTo($this->titolare, AvvisoDiSicurezzaNotification::class,
        fn (AvvisoDiSicurezzaNotification $avviso) => $avviso->evento === AvvisiDiSicurezza::DUE_FATTORI_DISATTIVATA);
});

it('Coda 267 — annullare una 2FA mai confermata non avvisa: non c\'era niente da togliere', function () {
    $this->actingAs($this->titolare)->withSession(confermata())->postJson(route('two-factor.enable'))->assertOk();

    $this->actingAs($this->titolare)->withSession(confermata())
        ->deleteJson(route('two-factor.disable'))
        ->assertSuccessful();

    expect($this->titolare->fresh()->two_factor_secret)->toBeNull();
    Notification::assertNotSentTo($this->titolare, AvvisoDiSicurezzaNotification::class);
});

it('Coda 267 — i codici di recupero rigenerati avvisano il titolare', function () {
    $this->titolare->forceFill([
        'two_factor_secret'         => encrypt((new Google2FA)->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode(['codice-uno'])),
        'two_factor_confirmed_at'   => now(),
    ])->save();

    $this->actingAs($this->titolare)->withSession(confermata())
        ->postJson(route('two-factor.regenerate-recovery-codes'))
        ->assertOk();

    Notification::assertSentTo($this->titolare, AvvisoDiSicurezzaNotification::class,
        fn (AvvisoDiSicurezzaNotification $avviso) => $avviso->evento === AvvisiDiSicurezza::CODICI_RIGENERATI);
});

it('Coda 267 — la prima password scelta dal link del primo accesso non manda l\'avviso', function () {
    $nuovo = User::factory()->create(['password' => null, 'email_verified_at' => now()]);
    $nuovo->assignRole(RoleEnum::UTENTE->value);

    $this->post(NewUserEmailNotification::link($nuovo), [
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('una-password-nuova', $nuovo->fresh()->password))->toBeTrue();
    Notification::assertNotSentTo($nuovo, AvvisoDiSicurezzaNotification::class);
});

it('Coda 267 — la prima password scelta dal link dell\'invito non manda l\'avviso', function () {
    $invito = Invito::create([
        'email'          => 'invitato@example.com',
        'building_codes' => [],
        'expires_at'     => now()->addDays(Invito::GIORNI_DI_VALIDITA),
    ]);

    $this->post((new InviteUserNotification($invito))->toMail($invito)->actionUrl, [
        'name'                  => 'Invitato',
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertSessionHasNoErrors();

    Notification::assertNotSentTo(User::where('email', 'invitato@example.com')->firstOrFail(), AvvisoDiSicurezzaNotification::class);
});

it('Coda 267 — le preferenze di notifica non spengono l\'avviso', function () {
    foreach (array_keys(array_merge(config('notifications.types.common', []), config('notifications.types.admin', []))) as $tipo) {
        NotificationPreference::create(['user_id' => $this->titolare->id, 'type' => $tipo, 'enabled' => false]);
    }

    $this->actingAs($this->titolare)
        ->put('/settings/password', [
            'current_password'      => 'password',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ]);

    Notification::assertSentTo($this->titolare, AvvisoDiSicurezzaNotification::class);
});

it('Coda 267 — se l\'invio fallisce la password cambia lo stesso e l\'errore va nel registro', function () {
    Notification::partialMock()->shouldReceive('send')->andThrow(new RuntimeException('server di posta irraggiungibile'));
    Log::spy();

    $this->actingAs($this->titolare)
        ->from('/settings/password')
        ->put('/settings/password', [
            'current_password'      => 'password',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/password');

    expect(Hash::check('una-password-nuova', $this->titolare->fresh()->password))->toBeTrue();
    Log::shouldHaveReceived('error')->withArgs(fn ($messaggio) => str_contains($messaggio, 'server di posta irraggiungibile'))->once();
});

it('Coda 267 — l\'avviso dice data e ora, non l\'indirizzo IP, e non nomina chi l\'ha fatto se è il titolare', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 20:40:00', 'UTC'));

    $this->actingAs($this->titolare)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->put('/settings/password', [
            'current_password'      => 'password',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ]);

    Notification::assertSentTo($this->titolare, AvvisoDiSicurezzaNotification::class,
        function (AvvisoDiSicurezzaNotification $avviso) {
            $testo = testoDellAvviso($avviso, $this->titolare);

            // Le 20:40 UTC sono le 21:40 a Roma (CET dal 25/10/2026): l'ora è quella dell'utente.
            return str_contains($testo, '26/10/2026')
                && str_contains($testo, '21:40')
                && ! str_contains($testo, '203.0.113.77')
                && $avviso->autore === null;
        });

    Carbon::setTestNow();
});

it('Coda 267 — l\'avviso parte in coda, come gli altri avvisi del gestionale', function () {
    expect(new AvvisoDiSicurezzaNotification(AvvisiDiSicurezza::PASSWORD_CAMBIATA, 'Maria'))->toBeInstanceOf(ShouldQueue::class);
});

// ─── I nomi nell'avviso: testo, non collegamenti ──────────────────────────────────────────────────────
// L'avviso è un messaggio Markdown, e i nomi che porta li scrive qualcun altro: il nome di chi ha fatto la
// modifica lo sceglie lui dal suo profilo, il nome del titolare lo corregge chi gestisce la sua scheda. Un nome
// con la sintassi di un collegamento non diventa un collegamento, in un avviso che parte dal gestionale e che
// deve dire al titolare di preoccuparsi: nell'avviso un nome arriva come testo semplice, su una riga, e corto,
// senza i caratteri che il Markdown interpreta, al massimo sessanta caratteri.

/** I collegamenti dell'email come la riceve il titolare, e il testo delle sue righe. */
function collegamentiDellAvviso(AvvisoDiSicurezzaNotification $avviso, object $destinatario): array
{
    preg_match_all('#<a [^>]*href="([^"]+)"#', (string) $avviso->toMail($destinatario)->render(), $trovati);

    return $trovati[1];
}

it('Coda 267 — il nome di chi ha fatto la modifica arriva nell\'avviso come testo corto, senza collegamenti', function () {
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(RoleEnum::COLLABORATORE->value);
    $nome = "l'amministrazione. Nessuna azione richiesta: per annullare usa [questo link](https://altrove.example/annulla) "
        .'<https://altrove.example/b> *subito* _ora_ `qui`';

    $this->actingAs($collaboratore)
        ->patch(route('profile.update'), ['name' => $nome, 'email' => $collaboratore->email])
        ->assertSessionHasNoErrors();

    $this->actingAs($collaboratore->fresh())
        ->put(route('utenti.update', ['utenti' => $this->titolare->id]), [
            'name' => $this->titolare->name, 'email' => 'maria.nuova@example.com',
            'roles' => $this->titolare->roles->first()->id, 'permissions' => [], 'anagrafica' => null,
        ])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(AvvisoDiSicurezzaNotification::class,
        function (AvvisoDiSicurezzaNotification $avviso, array $canali, AnonymousNotifiable $destinatario) {
            $collegamenti = collegamentiDellAvviso($avviso, $destinatario);
            $autore = (string) $avviso->autore;

            expect(collect($collegamenti)->filter(fn ($href) => str_contains($href, 'altrove.example'))->all())->toBe([])
                ->and($autore)->not->toMatch('/[\[\]()<>*_`]/')
                ->and(mb_strlen($autore))->toBeLessThanOrEqual(61)
                ->and($autore)->toStartWith("l'amministrazione. Nessuna azione richiesta");

            return true;
        });
});

it('Coda 267 — il nome del titolare, scritto da chi gestisce la sua scheda, arriva nel saluto come testo, senza collegamenti', function () {
    $collaboratore = User::factory()->create(['name' => 'Carlo Collaboratore', 'email_verified_at' => now()]);
    $collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->actingAs($collaboratore)
        ->put(route('utenti.update', ['utenti' => $this->titolare->id]), [
            'name' => "Maria\n\n# Nessuna azione richiesta [annulla qui](https://altrove.example/annulla)",
            'email' => 'maria.nuova@example.com',
            'roles' => $this->titolare->roles->first()->id, 'permissions' => [], 'anagrafica' => null,
        ])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(AvvisoDiSicurezzaNotification::class,
        function (AvvisoDiSicurezzaNotification $avviso, array $canali, AnonymousNotifiable $destinatario) {
            $collegamenti = collegamentiDellAvviso($avviso, $destinatario);

            expect(collect($collegamenti)->filter(fn ($href) => str_contains($href, 'altrove.example'))->all())->toBe([])
                ->and($avviso->nome)->not->toMatch('/[\[\]()<>*_`\n]/')
                ->and($avviso->nome)->toStartWith('Maria');

            return true;
        });
});

it('Coda 267 — un nome comune arriva nell\'avviso com\'è', function () {
    $amministratore = User::factory()->create(['name' => "Anna D'Àngelo-Rossi", 'email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    app(AvvisiDiSicurezza::class)->emailCambiata($this->titolare, 'maria@example.com', $amministratore);

    Notification::assertSentOnDemand(AvvisoDiSicurezzaNotification::class,
        fn (AvvisoDiSicurezzaNotification $avviso) => $avviso->autore === "Anna D'Àngelo-Rossi" && $avviso->nome === 'Maria Titolare');
});
