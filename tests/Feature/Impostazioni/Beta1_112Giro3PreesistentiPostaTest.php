<?php

/**
 * Coda 121 (decisione 188.4) — sotto la prova della posta, quando la connessione è stata rifiutata e la porta non è la 587, un suggerimento.
 *
 * Un amministratore che non riusciva a collegarsi al server di posta del suo hosting (le porte 25 e 465 rifiutate, la 587 no) ha ricevuto la risposta
 * che molti provider bloccano le connessioni in uscita su quelle porte, e che la pagina lo avrebbe detto. Il rifiuto della connessione esce dal sistema
 * operativo prima di qualsiasi byte SMTP: la prova non può sapere se il server non ascolta o se il provider blocca, e la frase le dice tutte e due, con la
 * porta scritta nel modulo e la strada che di solito apre, la 587 con TLS.
 *
 * Il suggerimento è una chiave a parte della risposta (`suggerimento`): il motivo (`message`) resta quello della libreria, e nel registro delle email
 * (`mail_logs.error_message`) e nel registro del programma finisce solo quello. Compare solo se il messaggio della libreria è un rifiuto di connessione
 * (la forma è fissata qui sotto, con il trasporto costruito come lo costruisce il prodotto verso una porta chiusa di questo computer) e la porta è un numero
 * diverso da 587; un tempo scaduto, un server che risponde con un errore, la 587 rifiutata, la porta vuota e sendmail non hanno il suggerimento.
 *
 * I fatti della libreria (Symfony Mailer, come lo installa il progetto):
 *
 * | fatto | letto o provato | test |
 * | un rifiuto della connessione è «Connection could not be established with host "<host>:<porta>": stream_socket_client(): Unable to connect to <schema>://… (Connection refused)» | provato | «la forma del rifiuto…» |
 * | con la cifratura SSL il rifiuto ha la stessa forma, con «ssl://» | provato | «con SSL…» |
 * | un server che accetta la connessione e non saluta dà «Connection to "…" timed out.», che non è un rifiuto | provato | «un server che non risponde…» |
 * | un errore di connessione che scade (errno 110) ha «(Connection timed out)» al posto di «(Connection refused)» | letto (PHP, strerror), non riproducibile in locale | «un tempo scaduto nella connessione…» |
 * | un server raggiunto che risponde con un errore usa «Expected response code…», anche se il suo testo nomina un rifiuto | letto (`SmtpTransport::assertResponseCode`) | «un server che risponde…» |
 */

use App\Enums\Role;
use App\Models\MailLog;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

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

/** L'amministratore con la password ridigitata da poco, che manda la richiesta di prova come la manda la pagina. */
function giro3Prova(object $test, array $campi = [])
{
    return $test->actingAs($test->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('admin.settings.mail.test'), array_merge([
            'mail_enabled' => true, 'mail_driver' => 'smtp', 'mail_host' => 'smtp.vero.example', 'mail_port' => 587,
            'mail_username' => 'utente@vero.example', 'mail_password' => 'password-del-modulo', 'mail_encryption' => 'tls',
            'mail_from_address' => 'condominio@vero.example', 'mail_from_name' => 'Studio', 'test_email' => 'prova@vero.example',
        ], $campi));
}

/** Una porta di questo computer su cui non ascolta nessuno: la connessione viene rifiutata subito, senza rete. */
function giro3PortaChiusa(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    [, $porta] = explode(':', stream_socket_get_name($server, false));
    fclose($server);

    return (int) $porta;
}

/** Il server in ascolto che accetta la connessione e non dice niente: il saluto non arriva. Da tenere in una variabile finché dura la prova. */
function giro3ServerMuto(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    [, $porta] = explode(':', stream_socket_get_name($server, false));

    return [$server, (int) $porta];
}

/** Un trasporto SMTP che fallisce con il messaggio dato, nella forma in cui la libreria lo scrive: per i messaggi che non si riproducono in locale. */
function giro3TrasportoCheFallisce(string $messaggio): void
{
    Mail::extend('smtp', fn () => new class($messaggio) implements TransportInterface
    {
        public function __construct(private string $messaggio) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException($this->messaggio);
        }

        public function __toString(): string
        {
            return 'smtp://prova';
        }
    });
}

/** La frase della Coda 121, per una porta, scritta per esteso: il testo che l'amministratore legge. */
function giro3Frase(int $porta): string
{
    return "La connessione alla porta {$porta} è stata rifiutata: o il server di posta non la accetta su quella porta, o le connessioni in uscita su quella porta sono bloccate dal tuo server o dal suo provider "
        .'(alcuni provider lo fanno per la 25 e la 465). Prova la 587 con la crittografia TLS.';
}

const GIRO3_FORMA_DEL_RIFIUTO = '/^Connection could not be established with host "[^"]+": stream_socket_client\(\): Unable to connect to [^ ]+ \(Connection refused\)$/';

// ─── I fatti della libreria ──────────────────────────────────────────────────

it('Coda 121 — la forma del rifiuto della connessione, con il trasporto costruito come lo costruisce il prodotto, verso una porta chiusa', function () {
    $porta = giro3PortaChiusa();

    $motivo = giro3Prova($this, ['mail_host' => '127.0.0.1', 'mail_port' => $porta, 'mail_encryption' => 'null'])
        ->assertStatus(500)
        ->json('message');

    expect($motivo)->toMatch(GIRO3_FORMA_DEL_RIFIUTO)->and($motivo)->toContain("127.0.0.1:{$porta}");
});

it('Coda 121 — con SSL il rifiuto ha la stessa forma', function () {
    $porta = giro3PortaChiusa();

    $motivo = giro3Prova($this, ['mail_host' => '127.0.0.1', 'mail_port' => $porta, 'mail_encryption' => 'ssl'])
        ->assertStatus(500)
        ->json('message');

    expect($motivo)->toMatch(GIRO3_FORMA_DEL_RIFIUTO)->and($motivo)->toContain("ssl://127.0.0.1:{$porta}");
});

it('Coda 121 — un server che accetta la connessione e non risponde dà un tempo scaduto, che non è un rifiuto', function () {
    [$server, $porta] = giro3ServerMuto();
    // La prova della pagina non imposta un tempo massimo (resta quello di PHP): qui lo si abbassa per non aspettare un minuto.
    config(['mail.mailers.smtp.timeout' => 1]);

    $motivo = giro3Prova($this, ['mail_host' => '127.0.0.1', 'mail_port' => $porta, 'mail_encryption' => 'null'])
        ->assertStatus(500)
        ->json('message');

    expect($motivo)->toMatch('/^Connection to "[^"]+" timed out\.$/')->and($motivo)->not->toContain('Connection refused');
    fclose($server);
});

// ─── Il suggerimento ─────────────────────────────────────────────────────────

it('Coda 121 — la connessione rifiutata su una porta che non è la 587: la risposta dà il motivo della libreria e, a parte, il suggerimento con la porta della richiesta', function () {
    $porta = giro3PortaChiusa();

    $risposta = giro3Prova($this, ['mail_host' => '127.0.0.1', 'mail_port' => $porta, 'mail_encryption' => 'null'])
        ->assertStatus(500)
        ->assertJsonPath('success', false);

    expect($risposta->json('message'))->toMatch(GIRO3_FORMA_DEL_RIFIUTO)
        ->and($risposta->json('suggerimento'))->toBe(giro3Frase($porta))
        ->and($risposta->json('message'))->not->toContain('587');
});

it('Coda 121 — il suggerimento non entra nel registro delle email né in quello del programma: restano il motivo della libreria', function () {
    Log::spy();
    $porta = giro3PortaChiusa();

    $motivo = giro3Prova($this, ['mail_host' => '127.0.0.1', 'mail_port' => $porta, 'mail_encryption' => 'null'])
        ->assertStatus(500)
        ->json('message');

    $riga = MailLog::first();
    expect($riga->error_message)->toBe($motivo)->and($riga->error_message)->not->toContain('587');
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $messaggio, array $contesto = []) => str_contains($messaggio, $motivo)
        && ! str_contains($messaggio, '587') && ! str_contains(json_encode($contesto), 'suggerimento'));
});

it('Coda 121 — il rifiuto del server del forum, sulla 465 con SSL: il suggerimento nomina la 465', function () {
    giro3TrasportoCheFallisce('Connection could not be established with host "ssl://mail.your-server.de:465": stream_socket_client(): Unable to connect to ssl://mail.your-server.de:465 (Connection refused)');

    giro3Prova($this, ['mail_host' => 'mail.your-server.de', 'mail_port' => 465, 'mail_encryption' => 'ssl'])
        ->assertStatus(500)
        ->assertJsonPath('suggerimento', giro3Frase(465));
});

it('Coda 121 — il rifiuto sulla 25 e quello sulla 2465 (la porta alternativa di Amazon): il suggerimento nomina la porta della richiesta', function (int $porta) {
    giro3TrasportoCheFallisce("Connection could not be established with host \"mail.vero.example:{$porta}\": stream_socket_client(): Unable to connect to mail.vero.example:{$porta} (Connection refused)");

    giro3Prova($this, ['mail_host' => 'mail.vero.example', 'mail_port' => $porta, 'mail_encryption' => 'null'])
        ->assertStatus(500)
        ->assertJsonPath('suggerimento', giro3Frase($porta));
})->with([25, 2465]);

it('Coda 121 — la 587 rifiutata non ha il suggerimento: è proprio la porta che il suggerimento consiglia', function () {
    giro3TrasportoCheFallisce('Connection could not be established with host "mail.vero.example:587": stream_socket_client(): Unable to connect to mail.vero.example:587 (Connection refused)');

    $risposta = giro3Prova($this, ['mail_host' => 'mail.vero.example', 'mail_port' => 587, 'mail_encryption' => 'tls'])->assertStatus(500);

    expect($risposta->json())->not->toHaveKey('suggerimento')->and($risposta->json('success'))->toBeFalse();
});

it('Coda 121 — con la porta scritta come testo («587 », «465») il numero è quello della richiesta', function () {
    giro3TrasportoCheFallisce('Connection could not be established with host "mail.vero.example:587": stream_socket_client(): Unable to connect to mail.vero.example:587 (Connection refused)');
    giro3Prova($this, ['mail_host' => 'mail.vero.example', 'mail_port' => '587', 'mail_encryption' => 'tls'])
        ->assertStatus(500)
        ->assertJsonMissingPath('suggerimento');

    giro3TrasportoCheFallisce('Connection could not be established with host "mail.vero.example:465": stream_socket_client(): Unable to connect to mail.vero.example:465 (Connection refused)');
    giro3Prova($this, ['mail_host' => 'mail.vero.example', 'mail_port' => '465', 'mail_encryption' => 'ssl'])
        ->assertStatus(500)
        ->assertJsonPath('suggerimento', giro3Frase(465));
});

it('Coda 121 — un tempo scaduto nella connessione non ha il suggerimento: non è un rifiuto', function () {
    giro3TrasportoCheFallisce('Connection could not be established with host "ssl://mail.your-server.de:465": stream_socket_client(): Unable to connect to ssl://mail.your-server.de:465 (Connection timed out)');

    giro3Prova($this, ['mail_host' => 'mail.your-server.de', 'mail_port' => 465, 'mail_encryption' => 'ssl'])
        ->assertStatus(500)
        ->assertJsonMissingPath('suggerimento')
        ->assertJsonPath('message', 'Connection could not be established with host "ssl://mail.your-server.de:465": stream_socket_client(): Unable to connect to ssl://mail.your-server.de:465 (Connection timed out)');
});

it('Coda 121 — un server che non risponde (tempo scaduto in lettura, provato davvero) non ha il suggerimento', function () {
    [$server, $porta] = giro3ServerMuto();
    config(['mail.mailers.smtp.timeout' => 1]);

    giro3Prova($this, ['mail_host' => '127.0.0.1', 'mail_port' => $porta, 'mail_encryption' => 'null'])
        ->assertStatus(500)
        ->assertJsonMissingPath('suggerimento');
    fclose($server);
});

it('Coda 121 — un server raggiunto che risponde con un errore non ha il suggerimento, anche se il suo testo nomina un rifiuto', function () {
    giro3TrasportoCheFallisce('Expected response code "250" but got code "554", with message "554 5.7.1 Connection refused by policy".');

    giro3Prova($this, ['mail_host' => 'mail.vero.example', 'mail_port' => 465, 'mail_encryption' => 'ssl'])
        ->assertStatus(500)
        ->assertJsonMissingPath('suggerimento');
});

it('Coda 121 — una porta vuota non ha il suggerimento: la prova non sa quale porta ha provato a usare', function () {
    giro3TrasportoCheFallisce('Connection could not be established with host "mail.vero.example:25": stream_socket_client(): Unable to connect to mail.vero.example:25 (Connection refused)');

    giro3Prova($this, ['mail_host' => 'mail.vero.example', 'mail_port' => '', 'mail_encryption' => 'null'])
        ->assertStatus(500)
        ->assertJsonMissingPath('suggerimento');
});

it('Coda 121 — con sendmail la prova fallita non ha il suggerimento, anche se il modulo porta la porta dell\'SMTP (la pagina manda tutti i campi)', function () {
    config(['mail.mailers.sendmail.path' => '/percorso/che/non/esiste/sendmail -bs']);

    $risposta = $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('admin.settings.mail.test'), [
            'mail_driver' => 'sendmail', 'mail_host' => 'mail.vero.example', 'mail_port' => 465, 'mail_encryption' => 'ssl',
            'mail_from_address' => 'condominio@vero.example', 'mail_from_name' => 'Studio', 'test_email' => 'prova@vero.example',
        ])
        ->assertStatus(500);

    expect($risposta->json())->not->toHaveKey('suggerimento');
});

it('Coda 121 — controprova: la prova riuscita risponde solo «success», senza suggerimento', function () {
    Mail::fake();

    $risposta = giro3Prova($this, ['mail_port' => 465, 'mail_encryption' => 'ssl'])->assertOk();

    expect($risposta->json())->toBe(['success' => true]);
});

// ─── Le quattro lingue ───────────────────────────────────────────────────────

it('Coda 121 — il suggerimento è nelle quattro lingue, con la porta e la 587', function (string $lingua, string $inizio, string $rifiutata, string $consiglio) {
    $frase = trans('impostazioni.error_test_mail_connection_refused', ['porta' => 465], $lingua);

    expect($frase)->toStartWith($inizio)->and($frase)->toContain($rifiutata)->and($frase)->toEndWith($consiglio)->and($frase)->not->toContain(':porta');
})->with([
    'italiano' => ['it', 'La connessione alla porta 465 è stata rifiutata:', 'sono bloccate dal tuo server o dal suo provider (alcuni provider lo fanno per la 25 e la 465)', 'Prova la 587 con la crittografia TLS.'],
    'inglese' => ['en', 'The connection to port 465 was refused:', 'are blocked by your server or its provider (some providers do so for ports 25 and 465)', 'Try port 587 with TLS encryption.'],
    'portoghese' => ['pt', 'A ligação à porta 465 foi recusada:', 'estão bloqueadas pelo seu servidor ou pelo respetivo fornecedor (alguns fornecedores fazem-no nas portas 25 e 465)', 'Experimente a porta 587 com a encriptação TLS.'],
    'spagnolo' => ['es', 'La conexión al puerto 465 ha sido rechazada:', 'están bloqueadas por tu servidor o por su proveedor (algunos proveedores lo hacen con los puertos 25 y 465)', 'Prueba el puerto 587 con el cifrado TLS.'],
]);

it('Coda 121 — il suggerimento della risposta è nella lingua dell\'installazione', function () {
    giro3TrasportoCheFallisce('Connection could not be established with host "mail.vero.example:465": stream_socket_client(): Unable to connect to mail.vero.example:465 (Connection refused)');
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->language = 'en';
    $impostazioni->save();

    $suggerimento = giro3Prova($this, ['mail_host' => 'mail.vero.example', 'mail_port' => 465, 'mail_encryption' => 'ssl'])
        ->assertStatus(500)
        ->json('suggerimento');

    expect($suggerimento)->toStartWith('The connection to port 465 was refused:');
});
