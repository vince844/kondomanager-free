<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, trovato dalla revisione avversariale (preesistente dalla
 * 1.9.0): le rotte di cron, posta e registro delle email avevano solo `auth`, e i tre controller
 * (`CronSettingsController`, `MailSettingsController`, `LogsController`) nessun controllo. L'hub
 * delle impostazioni dava 403, ma bastava conoscere l'indirizzo. Qualunque utente con il login
 * leggeva destinatari e oggetti di tutte le email e il token del cron; con `mail/test` si faceva
 * consegnare la password SMTP salvata, decifrata, sull'host scelto da lui; con `mail` deviava tutta
 * la posta dell'installazione, reset password dell'amministratore compreso.
 *
 * Ora le sette rotte stanno dietro `permission:Gestisci impostazioni generali`, lo stesso permesso
 * dell'hub: nei ruoli di serie ce l'ha solo l'amministratore.
 *
 * **Cosa resta scoperto**: il contenuto delle tre pagine per l'amministratore (qui si prova solo
 * che si aprono); le altre rotte dello stesso gruppo (`generali`, `stampe`, `backups`,
 * `installazione`) hanno già il loro controllo nel controller e non sono ripetute qui.
 */

use App\Enums\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Non serve a contare le email (vedi sotto): se il blocco si rompesse, evita che il test di
    // connessione provi davvero a collegarsi all'host indicato nella richiesta.
    Mail::fake();

    $posta = app(MailSettings::class);
    $posta->mail_host = 'smtp.vero.example';
    $posta->mail_password = Crypt::encryptString('segreto-smtp-vero');
    $posta->save();

    $generali = app(GeneralSettings::class);
    $generali->external_cron_enabled = true;
    $generali->external_cron_token = 'token-originale';
    $generali->save();
});

function utenteConRuolo(Role $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo->value);

    return $user;
}

dataset('ruoli senza impostazioni', [
    'condòmino'    => [Role::UTENTE],
    'fornitore'    => [Role::FORNITORE],
    'collaboratore' => [Role::COLLABORATORE],
]);

test('le pagine di cron, posta e registro sono negate', function (Role $ruolo) {
    $user = utenteConRuolo($ruolo);

    $this->actingAs($user)->get(route('impostazioni.cron'))->assertForbidden();
    $this->actingAs($user)->get(route('impostazioni.mail'))->assertForbidden();
    $this->actingAs($user)->get(route('logs.index'))->assertForbidden();
})->with('ruoli senza impostazioni');

test('le modifiche di cron e posta sono negate e non cambiano niente', function (Role $ruolo) {
    $user = utenteConRuolo($ruolo);

    $this->actingAs($user)->post(route('impostazioni.cron.update'), ['enabled' => false])->assertForbidden();
    $this->actingAs($user)->post(route('impostazioni.cron.regenerate'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.settings.mail.update'), [
        'mail_enabled'      => true,
        'mail_driver'       => 'smtp',
        'mail_host'         => 'smtp.attaccante.example',
        'mail_port'         => 587,
        'mail_from_address' => 'finto@attaccante.example',
        'mail_from_name'    => 'Finto',
    ])->assertForbidden();
    $this->actingAs($user)->post(route('admin.settings.mail.test'), [
        'mail_driver'       => 'smtp',
        'mail_host'         => 'smtp.attaccante.example',
        'mail_port'         => 587,
        'mail_from_address' => 'finto@attaccante.example',
        'test_email'        => 'finto@attaccante.example',
    ])->assertForbidden();

    $generali = app(GeneralSettings::class)->refresh();
    $posta = app(MailSettings::class)->refresh();

    expect($generali->external_cron_enabled)->toBeTrue()
        ->and($generali->external_cron_token)->toBe('token-originale')
        ->and($posta->mail_host)->toBe('smtp.vero.example')
        // Il test di connessione non è mai partito: la password salvata non è finita nella
        // configurazione verso l'host indicato nella richiesta. È l'unica prova possibile:
        // testConnection invia con Mail::raw(), che sul fake non registra niente, quindi
        // Mail::assertNothingSent() resterebbe verde anche a test eseguito.
        ->and(config('mail.mailers.smtp.host'))->not->toBe('smtp.attaccante.example')
        ->and(config('mail.mailers.smtp.password'))->not->toBe('segreto-smtp-vero');
})->with('ruoli senza impostazioni');

test('l\'amministratore apre cron, posta e registro', function () {
    $amministratore = utenteConRuolo(Role::AMMINISTRATORE);

    $this->actingAs($amministratore)->get(route('impostazioni.cron'))->assertOk();
    $this->actingAs($amministratore)->get(route('impostazioni.mail'))->assertOk();
    $this->actingAs($amministratore)->get(route('logs.index'))->assertOk();
});
