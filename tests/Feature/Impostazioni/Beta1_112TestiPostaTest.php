<?php

/**
 * Coda 454 e Coda 121 (punti 1–3) — la pagina della posta dice che cosa fa la prova e perché fallisce.
 *
 * Coda 454. La prova di invio non usa i dati salvati: il controller applica i valori che la pagina manda (`mail_host`, `mail_port`,
 * `mail_encryption`…), invia, e non salva niente. L'avviso accanto al pulsante, con modifiche non salvate, invitava invece a «salvare prima
 * di inviare il test», e chi lo leggeva salvava senza motivo o credeva che la prova usasse i dati vecchi. La frase nuova dice ciò che la
 * prova fa. Le quattro lingue del prodotto; quella spagnola era ancora in inglese.
 *
 * Coda 121 (decisione 184). Un test fallito non lasciava il motivo che dove nessuno lo cerca: nel registro delle email (`mail_logs`) con la
 * tabella della pagina dei registri che non lo mostrava, e in niente nel registro del programma. Qui: il motivo arriva alla pagina dei
 * registri (la tabella lo mostra: `impostazioniLogs.test.ts`) e il controller lo scrive anche in `storage/logs/laravel.log`, senza la
 * password. La guida sul mittente è fissata da `MailSetupGuide.test.ts`.
 */

use App\Enums\Role;
use App\Models\MailLog;
use App\Models\User;
use App\Settings\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

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
function amministratoreDellaProvaDiPosta($test)
{
    return $test->actingAs($test->amministratore)->withSession(['auth.password_confirmed_at' => time()]);
}

/** Il corpo come lo manda la pagina: i campi del modulo e l'indirizzo della prova. */
function corpoDellaProvaDiPosta(array $cambi = []): array
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
        'test_email' => 'prova@vero.example',
    ], $cambi);
}

/** Una porta di questo computer su cui non ascolta nessuno: la connessione viene rifiutata subito, senza rete. */
function portaChiusaDiQuestoComputer(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    [, $porta] = explode(':', stream_socket_get_name($server, false));
    fclose($server);

    return (int) $porta;
}

// ─── Coda 454 ────────────────────────────────────────────────────────────────

it('Coda 454 — l\'avviso con modifiche non salvate dice che la prova non le salva, e non invita a salvare prima di inviare', function (string $lingua, string $iniziaCon, string $nonSalva, string $invitoAPrima) {
    $frase = trans('impostazioni.dialogs.test_unsaved_warning', [], $lingua);

    expect($frase)->toStartWith($iniziaCon)
        ->and(mb_strtolower($frase))->toContain($nonSalva)->not->toContain($invitoAPrima);
})->with([
    'italiano' => ['it', 'Hai modifiche non salvate.', 'usa i valori che vedi nel modulo ma non li salva', 'prima di inviare'],
    'inglese' => ['en', 'You have unsaved changes.', 'uses the values you see in the form but does not save them', 'before sending'],
    'portoghese' => ['pt', 'Tem alterações não guardadas.', 'usa os valores que vê no formulário mas não os guarda', 'antes de enviar'],
    'spagnolo' => ['es', 'Tienes cambios sin guardar.', 'usa los valores que ves en el formulario, pero no los guarda', 'antes de enviar'],
]);

it('Coda 454 — la frase spagnola non è più quella inglese', function () {
    expect(trans('impostazioni.dialogs.test_unsaved_warning', [], 'es'))
        ->not->toBe(trans('impostazioni.dialogs.test_unsaved_warning', [], 'en'));
});

it('Coda 454 — controprova: la prova usa i valori del modulo, non quelli salvati, e non salva niente', function () {
    Mail::fake();

    amministratoreDellaProvaDiPosta($this)
        ->postJson(route('admin.settings.mail.test'), corpoDellaProvaDiPosta([
            'mail_host' => 'smtp.altrove.example',
            'mail_port' => 2525,
            'mail_encryption' => 'null',
            'mail_username' => 'altro@altrove.example',
            'mail_password' => 'password-del-modulo',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    // La prova ha usato ciò che era scritto nel modulo…
    expect(config('mail.mailers.smtp.host'))->toBe('smtp.altrove.example')
        ->and(config('mail.mailers.smtp.port'))->toBe(2525)
        ->and(config('mail.mailers.smtp.password'))->toBe('password-del-modulo');

    // …e non ha toccato ciò che era salvato.
    $salvate = app(MailSettings::class)->refresh();
    expect($salvate->mail_host)->toBe('smtp.vero.example')
        ->and((int) $salvate->mail_port)->toBe(587)
        ->and($salvate->mail_username)->toBe('utente@vero.example')
        ->and(Crypt::decryptString($salvate->mail_password))->toBe('segreto-smtp-vero');
});

// ─── Coda 121, punti 1 e 2 ───────────────────────────────────────────────────

it('Coda 121 — la prova fallita risponde con il motivo e lo scrive nel registro del programma, senza la password', function () {
    Log::spy();
    $porta = portaChiusaDiQuestoComputer();

    $risposta = amministratoreDellaProvaDiPosta($this)
        ->postJson(route('admin.settings.mail.test'), corpoDellaProvaDiPosta([
            'mail_host' => '127.0.0.1',
            'mail_port' => $porta,
            'mail_encryption' => 'null',
            'mail_password' => 'segreto-da-non-scrivere',
        ]))
        ->assertStatus(500)
        ->assertJsonPath('success', false);

    $motivo = $risposta->json('message');
    expect($motivo)->toBeString()->not->toBe('');

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $messaggio, array $contesto = []) use ($motivo, $porta) {
        return str_contains($messaggio, $motivo)
            && ($contesto['host'] ?? null) === '127.0.0.1'
            && (int) ($contesto['porta'] ?? 0) === $porta
            && ! str_contains(json_encode([$messaggio, $contesto]), 'segreto-da-non-scrivere');
    });
});

it('Coda 121 — con sendmail la prova fallita scrive lo stesso il motivo nel registro del programma, col driver e senza server', function () {
    Log::spy();
    // Un eseguibile che non c'è: la prova fallisce subito, senza lanciare niente.
    config(['mail.mailers.sendmail.path' => '/percorso/che/non/esiste/sendmail -bs']);

    $motivo = amministratoreDellaProvaDiPosta($this)
        ->postJson(route('admin.settings.mail.test'), [
            'mail_driver' => 'sendmail',
            'mail_from_address' => 'condominio@vero.example',
            'mail_from_name' => 'Studio',
            'test_email' => 'prova@vero.example',
        ])
        ->assertStatus(500)
        ->json('message');

    expect($motivo)->toBeString()->not->toBe('');
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $messaggio, array $contesto = []) => str_contains($messaggio, $motivo)
        && ($contesto['driver'] ?? null) === 'sendmail'
        && array_key_exists('host', $contesto) && $contesto['host'] === null);
    expect(MailLog::first()->mailer)->toBe('sendmail');
});

it('Coda 121 — la prova fallita resta nel registro delle email col suo motivo, e la pagina dei registri lo riceve', function () {
    $porta = portaChiusaDiQuestoComputer();

    $motivo = amministratoreDellaProvaDiPosta($this)
        ->postJson(route('admin.settings.mail.test'), corpoDellaProvaDiPosta([
            'mail_host' => '127.0.0.1',
            'mail_port' => $porta,
            'mail_encryption' => 'null',
            'mail_password' => 'password-del-modulo',
        ]))
        ->assertStatus(500)
        ->json('message');

    $riga = MailLog::first();
    expect($riga->status)->toBe('failed')
        ->and($riga->recipient)->toBe('prova@vero.example')
        ->and($riga->error_message)->toBe($motivo);

    $this->actingAs($this->amministratore)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('impostazioni/impostazioniLogs')
            ->where('mailLogs.data.0.status', 'failed')
            ->where('mailLogs.data.0.error_message', $motivo));
});

it('Coda 121 — controprova: una prova riuscita non scrive niente nel registro degli errori', function () {
    Mail::fake();
    Log::spy();

    amministratoreDellaProvaDiPosta($this)
        ->postJson(route('admin.settings.mail.test'), corpoDellaProvaDiPosta())
        ->assertOk();

    Log::shouldNotHaveReceived('error');
    expect(MailLog::count())->toBe(0);
});
