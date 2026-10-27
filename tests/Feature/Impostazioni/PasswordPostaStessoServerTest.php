<?php

/**
 * # Coda 311 — la password SMTP salvata vale solo verso il server per cui è stata salvata
 *
 * La pagina della posta lascia vuoto il campo della password quando ce n'è una salvata («lascia
 * vuoto per mantenere la password attuale»): il campo vuoto vuol dire «la password di prima».
 * Con il campo vuoto la prova e il salvataggio usano la password salvata solo verso lo stesso
 * server, la stessa porta e la stessa cifratura: la password salvata non parte mai verso
 * un'altra destinazione, non resta accanto a un server nuovo, e la pagina non la riceve, nemmeno
 * cifrata.
 *
 * La password salvata si usa solo se server (senza maiuscole e spazi), porta (come numero) e
 * cifratura («nessuna» scritta in qualunque modo) sono quelli salvati; altrimenti la prova risponde
 * 422 con la richiesta di riscriverla (prima di provare, quindi niente nel registro delle email), e il
 * salvataggio dà lo stesso errore sul campo della password, senza cambiare niente. La pagina riceve le
 * impostazioni senza la password, e sa solo se ce n'è una salvata. La prova chiede un indirizzo email
 * valido e si può lanciare al massimo sei volte al minuto.
 */

use App\Enums\Role;
use App\Models\MailLog;
use App\Models\User;
use App\Settings\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // La prova invia con `Mail::raw()`: con il finto non parte nessuna connessione, e la password
    // che la prova userebbe resta nella configurazione del mailer, dove il test la legge.
    Mail::fake();

    $posta = app(MailSettings::class);
    $posta->mail_enabled = true;
    $posta->mail_driver = 'smtp';
    $posta->mail_host = 'smtp.vero.example';
    $posta->mail_port = 587;
    $posta->mail_username = 'utente@vero.example';
    $posta->mail_password = Crypt::encryptString('segreto-smtp-vero');
    $posta->mail_encryption = 'tls';
    $posta->mail_from_address = 'condominio@vero.example';
    $posta->mail_from_name = 'Studio';
    $posta->save();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(Role::AMMINISTRATORE->value);
});

/** L'amministratore con la password ridigitata da poco (Coda 261). */
function amministratorePostaConfermato($test)
{
    return $test->actingAs($test->amministratore)->withSession(['auth.password_confirmed_at' => time()]);
}

/** Il corpo come lo manda la pagina: i campi del modulo, la password vuota, l'indirizzo della prova. */
function corpoPosta(array $cambi = []): array
{
    return array_merge([
        'mail_enabled' => true,
        'mail_driver' => 'smtp',
        'mail_host' => 'smtp.vero.example',
        'mail_port' => 587,
        'mail_username' => 'utente@vero.example',
        'mail_password' => '',
        'mail_encryption' => 'tls',
        'mail_from_address' => 'condominio@vero.example',
        'mail_from_name' => 'Studio',
    ], $cambi);
}

function passwordPostaSalvata(): ?string
{
    $salvata = app(MailSettings::class)->refresh()->mail_password;

    return $salvata ? Crypt::decryptString($salvata) : null;
}

dataset('destinazioni diverse da quella salvata', [
    'altro server' => [['mail_host' => 'smtp.altrove.example']],
    'altra porta' => [['mail_port' => 2525]],
    'senza cifratura sullo stesso server' => [['mail_encryption' => 'null']],
    'altra cifratura' => [['mail_encryption' => 'ssl']],
    'altro server con la stessa porta come testo' => [['mail_host' => 'smtp.altrove.example', 'mail_port' => '587']],
]);

// ─── La prova ────────────────────────────────────────────────────────────────

it('Coda 311 — la prova verso un altro server con la password vuota non usa quella salvata: 422', function (array $cambi) {
    amministratorePostaConfermato($this)
        ->postJson(route('admin.settings.mail.test'), corpoPosta($cambi + ['test_email' => 'prova@vero.example']))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('impostazioni.error_test_mail_password_required'));

    expect(config('mail.mailers.smtp.password'))->not->toBe('segreto-smtp-vero')
        // Il rifiuto arriva prima della prova: non è una prova fallita, non va nel registro.
        ->and(MailLog::count())->toBe(0);
})->with('destinazioni diverse da quella salvata');

it('Coda 311 — la prova verso lo stesso server usa la password salvata, con l\'host scritto in un altro modo', function (array $cambi) {
    amministratorePostaConfermato($this)
        ->postJson(route('admin.settings.mail.test'), corpoPosta($cambi + ['test_email' => 'prova@vero.example']))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(config('mail.mailers.smtp.password'))->toBe('segreto-smtp-vero');
})->with([
    'identico' => [[]],
    'maiuscole e spazi nell\'host, porta come testo' => [['mail_host' => ' SMTP.Vero.Example ', 'mail_port' => '587']],
]);

it('Coda 311 — «nessuna cifratura» scritta in modi diversi è la stessa cifratura', function (string $salvata, ?string $mandata) {
    $posta = app(MailSettings::class);
    $posta->mail_encryption = $salvata;
    $posta->save();

    amministratorePostaConfermato($this)
        ->postJson(route('admin.settings.mail.test'), corpoPosta(['mail_encryption' => $mandata, 'test_email' => 'prova@vero.example']))
        ->assertOk();

    expect(config('mail.mailers.smtp.password'))->toBe('segreto-smtp-vero');
})->with([
    'salvata «null», mandata vuota' => ['null', null],
    'salvata «null», mandata «none»' => ['null', 'none'],
    'salvata vuota, mandata «null»' => ['', 'null'],
]);

it('Coda 311 — con la password riscritta la prova verso un altro server parte con quella', function () {
    amministratorePostaConfermato($this)
        ->postJson(route('admin.settings.mail.test'), corpoPosta([
            'mail_host' => 'smtp.altrove.example', 'mail_password' => 'password-nuova', 'test_email' => 'prova@vero.example',
        ]))
        ->assertOk();

    expect(config('mail.mailers.smtp.password'))->toBe('password-nuova')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.altrove.example');
});

it('Coda 311 — una password fatta di uno zero è una password, non un campo vuoto', function () {
    amministratorePostaConfermato($this)
        ->postJson(route('admin.settings.mail.test'), corpoPosta([
            'mail_host' => 'smtp.altrove.example', 'mail_password' => '0', 'test_email' => 'prova@vero.example',
        ]))
        ->assertOk();

    expect(config('mail.mailers.smtp.password'))->toBe('0');
});

it('Coda 311 — la prova chiede un indirizzo email valido', function (array $cambi) {
    amministratorePostaConfermato($this)
        ->postJson(route('admin.settings.mail.test'), corpoPosta($cambi))
        ->assertStatus(422)
        ->assertJsonValidationErrors('test_email');

    expect(MailLog::count())->toBe(0);
})->with([
    'senza indirizzo' => [[]],
    'indirizzo non valido' => [['test_email' => 'non-una-email']],
]);

it('Coda 311 — al massimo sei prove al minuto', function () {
    $esiti = [];
    for ($i = 0; $i < 7; $i++) {
        $esiti[] = amministratorePostaConfermato($this)
            ->postJson(route('admin.settings.mail.test'), corpoPosta(['test_email' => 'prova@vero.example']))
            ->status();
    }

    expect($esiti)->toBe([200, 200, 200, 200, 200, 200, 429]);
});

// ─── Il salvataggio ──────────────────────────────────────────────────────────

it('Coda 311 — salvare un altro server senza riscrivere la password è un errore sul campo e non cambia niente', function (array $cambi) {
    amministratorePostaConfermato($this)
        ->from(route('impostazioni.mail'))
        ->post(route('admin.settings.mail.update'), corpoPosta($cambi))
        ->assertRedirect(route('impostazioni.mail'))
        ->assertSessionHasErrors(['mail_password' => __('impostazioni.error_save_mail_password_required')]);

    $posta = app(MailSettings::class)->refresh();
    expect($posta->mail_host)->toBe('smtp.vero.example')
        ->and($posta->mail_port)->toBe(587)
        ->and($posta->mail_encryption)->toBe('tls')
        ->and(passwordPostaSalvata())->toBe('segreto-smtp-vero');
})->with('destinazioni diverse da quella salvata');

it('Coda 311 — anche con la posta spenta un altro server senza password non si salva', function () {
    amministratorePostaConfermato($this)
        ->from(route('impostazioni.mail'))
        ->post(route('admin.settings.mail.update'), corpoPosta(['mail_enabled' => false, 'mail_host' => 'smtp.altrove.example']))
        ->assertSessionHasErrors('mail_password');

    expect(app(MailSettings::class)->refresh()->mail_host)->toBe('smtp.vero.example');
});

it('Coda 311 — salvare lo stesso server senza password tiene quella salvata', function () {
    amministratorePostaConfermato($this)
        ->from(route('impostazioni.mail'))
        ->post(route('admin.settings.mail.update'), corpoPosta(['mail_host' => 'SMTP.vero.example', 'mail_from_name' => 'Studio Rossi']))
        ->assertSessionHasNoErrors();

    $posta = app(MailSettings::class)->refresh();
    expect($posta->mail_from_name)->toBe('Studio Rossi')
        ->and(passwordPostaSalvata())->toBe('segreto-smtp-vero');
});

it('Coda 311 — salvare un altro server con la password nuova', function () {
    amministratorePostaConfermato($this)
        ->from(route('impostazioni.mail'))
        ->post(route('admin.settings.mail.update'), corpoPosta(['mail_host' => 'smtp.altrove.example', 'mail_password' => 'password-nuova']))
        ->assertSessionHasNoErrors();

    $posta = app(MailSettings::class)->refresh();
    expect($posta->mail_host)->toBe('smtp.altrove.example')
        ->and(passwordPostaSalvata())->toBe('password-nuova');
});

// ─── La pagina ───────────────────────────────────────────────────────────────

it('Coda 311 — la pagina della posta non riceve la password, nemmeno cifrata, e sa che ce n\'è una', function () {
    $cifrata = app(MailSettings::class)->mail_password;

    $risposta = amministratorePostaConfermato($this)->get(route('impostazioni.mail'))->assertOk();

    $risposta->assertInertia(fn (Assert $pagina) => $pagina
        ->component('impostazioni/impostazioniMail')
        ->missing('settings.mail_password')
        ->where('settings.mail_host', 'smtp.vero.example')
        ->where('settings.mail_port', 587)
        ->where('settings.mail_encryption', 'tls')
        ->where('settings.mail_username', 'utente@vero.example')
        ->where('password_set', true)
    );
    expect($risposta->getContent())->not->toContain($cifrata);
});

it('Coda 311 — senza password salvata la pagina lo sa', function () {
    $posta = app(MailSettings::class);
    $posta->mail_password = null;
    $posta->save();

    amministratorePostaConfermato($this)->get(route('impostazioni.mail'))
        ->assertInertia(fn (Assert $pagina) => $pagina->where('password_set', false)->missing('settings.mail_password'));
});

it('Coda 311 — la pagina aperta e salvata senza toccare niente salva e tiene la password', function (array $salvate) {
    $posta = app(MailSettings::class);
    foreach ($salvate as $campo => $valore) {
        $posta->{$campo} = $valore;
    }
    $posta->save();

    // Il corpo come lo costruisce `impostazioniMail.vue`: i campi ricevuti, la password vuota.
    $ricevute = amministratorePostaConfermato($this)->get(route('impostazioni.mail'))->viewData('page')['props']['settings'];
    $corpo = [...$ricevute, 'mail_password' => ''];

    amministratorePostaConfermato($this)
        ->from(route('impostazioni.mail'))
        ->post(route('admin.settings.mail.update'), $corpo)
        ->assertSessionHasNoErrors();

    $dopo = app(MailSettings::class)->refresh();
    foreach ($salvate as $campo => $valore) {
        expect($dopo->{$campo})->toBe($valore);
    }
    expect(passwordPostaSalvata())->toBe('segreto-smtp-vero');
})->with([
    'ssl, maiuscole, caratteri scomodi' => [[
        'mail_host' => 'Smtp.Esempio.IT', 'mail_port' => 465, 'mail_encryption' => 'ssl',
        'mail_username' => 'utente+posta@esempio.it', 'mail_from_name' => "Studio D'Angelo – Condominî", 'mail_enabled' => false,
    ]],
    'senza cifratura' => [[
        'mail_host' => 'relay.lan', 'mail_port' => 25, 'mail_encryption' => 'null', 'mail_username' => null,
    ]],
]);
