<?php

/**
 * Coda 258 — chi accetta un invito riceve il ruolo dei nuovi iscritti solo se è «utente» o «fornitore».
 *
 * ## Perché questo file esiste
 *
 * Il ruolo dei nuovi iscritti (`default_user_role`) lo sceglie l'amministratore fra tutti i ruoli, anche uno su
 * misura che dà poteri: nella demo è «Amministratore Demo» (decisione 136.2), e sceglierlo o accendere la
 * registrazione con quel ruolo chiede la password ridigitata. Lo stesso servizio registra anche chi accetta un
 * invito, e l'invito lo spedisce chi ha «Crea utenti», collaboratore compreso: la tabella `inviti` non registra
 * chi l'ha spedito né un ruolo. Gli inviti portano i codici dei palazzi, cioè sono per i condòmini: un account
 * che nasce da un invito riceve il ruolo dei nuovi iscritti solo se è «utente» o «fornitore», altrimenti
 * «utente», chiunque abbia spedito l'invito. Chi vuole dare un altro ruolo a un invitato lo fa dalla sua scheda.
 * La registrazione pubblica resta com'è.
 *
 * ## Cosa questo file NON copre
 *
 * Chi può spedire un invito (`InvitiTest.php`); il link firmato dell'invito (`RegistrazioneDaInvitoTest.php`);
 * il ruolo dei nuovi iscritti che non esiste più (`RuoloDeiNuoviIscrittiTest.php`).
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Condominio;
use App\Models\Invito;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Settings\GeneralSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    // Un ruolo su misura che dà poteri, come quello della demo.
    Role::create(['name' => 'Amministratore Demo', 'guard_name' => 'web', 'description' => 'Demo'])
        ->givePermissionTo([PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::VIEW_USERS->value, PermissionEnum::EDIT_USERS->value]);

    $this->condominio = Condominio::factory()->create(['codice_identificativo' => 'AUR001']);
});

function rciRuoloDeiNuoviIscritti(string $nome, bool $registrazione = false): void
{
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->default_user_role = $nome;
    $impostazioni->user_frontend_registration = $registrazione;
    $impostazioni->save();
}

function rciUtente(string $ruolo): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole($ruolo);

    return $utente;
}

/** Spedisce l'invito come lo manda la pagina «Invita utenti», poi lo accetta dal link della mail. */
function rciInvitaEAccetta($test, User $mittente, string $email, string $codice): ?User
{
    $test->actingAs($mittente)->from(route('inviti.create'))
        ->post(route('inviti.store'), ['emails' => [$email], 'buildings' => [$codice]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('inviti.index'));

    $invito = Invito::where('email', $email)->firstOrFail();
    auth()->logout();

    $test->post((new InviteUserNotification($invito))->toMail($invito)->actionUrl, [
        'name'                  => 'Invitato «Aurora» D\'Àngelo',
        'email'                 => $email,
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertRedirect(route('verification.notice'));

    return User::where('email', $email)->first();
}

it('Coda 258 — chi accetta un invito riceve il ruolo dei nuovi iscritti solo se è utente o fornitore, chiunque abbia spedito l\'invito', function (string $mittente, string $ruoloDeiNuovi, string $atteso) {
    rciRuoloDeiNuoviIscritti($ruoloDeiNuovi);

    $invitato = rciInvitaEAccetta($this, rciUtente($mittente), 'invitato@example.com', $this->condominio->codice_identificativo);

    expect($invitato?->getRoleNames()->all())->toBe([$atteso]);
})->with([
    'il collaboratore, con il ruolo dei nuovi iscritti «amministratore»'       => [RoleEnum::COLLABORATORE->value, RoleEnum::AMMINISTRATORE->value, RoleEnum::UTENTE->value],
    'il collaboratore, con il ruolo dei nuovi iscritti «collaboratore»'        => [RoleEnum::COLLABORATORE->value, RoleEnum::COLLABORATORE->value, RoleEnum::UTENTE->value],
    'il collaboratore, con un ruolo su misura'                                 => [RoleEnum::COLLABORATORE->value, 'Amministratore Demo', RoleEnum::UTENTE->value],
    'il collaboratore, con il ruolo dei nuovi iscritti «fornitore»'            => [RoleEnum::COLLABORATORE->value, RoleEnum::FORNITORE->value, RoleEnum::FORNITORE->value],
    'il collaboratore, con il ruolo dei nuovi iscritti «utente»'               => [RoleEnum::COLLABORATORE->value, RoleEnum::UTENTE->value, RoleEnum::UTENTE->value],
    'l\'amministratore, con il ruolo dei nuovi iscritti «amministratore»'      => [RoleEnum::AMMINISTRATORE->value, RoleEnum::AMMINISTRATORE->value, RoleEnum::UTENTE->value],
    'l\'amministratore, con il ruolo dei nuovi iscritti «collaboratore»'       => [RoleEnum::AMMINISTRATORE->value, RoleEnum::COLLABORATORE->value, RoleEnum::UTENTE->value],
    'l\'amministratore, con un ruolo su misura'                                => [RoleEnum::AMMINISTRATORE->value, 'Amministratore Demo', RoleEnum::UTENTE->value],
    'l\'amministratore, con il ruolo dei nuovi iscritti «fornitore»'           => [RoleEnum::AMMINISTRATORE->value, RoleEnum::FORNITORE->value, RoleEnum::FORNITORE->value],
    'l\'amministratore, con il ruolo dei nuovi iscritti «utente»'              => [RoleEnum::AMMINISTRATORE->value, RoleEnum::UTENTE->value, RoleEnum::UTENTE->value],
]);

it('Coda 258 — vale il ruolo dei nuovi iscritti di quando l\'invito si accetta, non di quando è partito', function () {
    // Spedito con «utente», accettato quando il ruolo dei nuovi iscritti è diventato «Amministratore Demo».
    rciRuoloDeiNuoviIscritti(RoleEnum::UTENTE->value);
    $this->actingAs(rciUtente(RoleEnum::COLLABORATORE->value))->from(route('inviti.create'))
        ->post(route('inviti.store'), ['emails' => ['tardivo@example.com'], 'buildings' => ['AUR001']])
        ->assertRedirect(route('inviti.index'));
    auth()->logout();

    rciRuoloDeiNuoviIscritti('Amministratore Demo');
    $invito = Invito::where('email', 'tardivo@example.com')->firstOrFail();
    $this->post((new InviteUserNotification($invito))->toMail($invito)->actionUrl, [
        'name'                  => 'Invitato',
        'email'                 => 'tardivo@example.com',
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'tardivo@example.com')->first()?->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
});

it('Coda 258 — con «Amministratore Demo» come ruolo dei nuovi iscritti la registrazione pubblica dà ancora quel ruolo, e un invito dà «utente»', function () {
    // Decisione 136.2: la registrazione pubblica della demo resta com'è.
    rciRuoloDeiNuoviIscritti('Amministratore Demo', registrazione: true);

    $this->post('/register', [
        'name'                  => 'Ospite della demo',
        'email'                 => 'ospite.demo@example.com',
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertRedirect(route('verification.notice', absolute: false));
    // La sessione dell'ospite appena registrato non vale per l'amministratore che spedisce l'invito.
    auth()->logout();
    $this->flushSession();

    expect(User::where('email', 'ospite.demo@example.com')->first()?->getRoleNames()->all())->toBe(['Amministratore Demo']);

    $invitato = rciInvitaEAccetta($this, rciUtente(RoleEnum::AMMINISTRATORE->value), 'invitato.demo@example.com', 'AUR001');

    expect($invitato?->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
});
