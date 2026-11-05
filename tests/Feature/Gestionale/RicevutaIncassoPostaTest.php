<?php

use App\Enums\Permission as Permesso;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\MailLog;
use App\Models\User;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\Email;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Il lato della posta della ricevuta: che cosa scrive il programma quando il server di posta non c'è o rifiuta, che cosa vede chi lavora e
 * che cosa arriva a chi riceve il messaggio. Un messaggio accodato quando il server c'era e partito quando non c'è più non scrive il PDF nel
 * file di log; i campi che non sono testo si rifiutano con un 422; l'invio a un indirizzo scritto a mano ha un limite; il messaggio non porta
 * gli identificativi del registro; l'errore intero del server lo legge solo chi gestisce le impostazioni.
 *
 * Che cosa NON copre: la consegna (il programma sa solo che il server ha accettato).
 */
function inviaUnaPosta($test, object $s, $incasso, array $campi, ?User $utente = null)
{
    return $test->actingAs($utente ?? amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]), $campi);
}

/** Un utente del pannello, con o senza il permesso delle impostazioni generali (quello che apre il registro delle email). */
function utenteDelPannello(string $ruolo, bool $impostazioni): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $r = Role::firstOrCreate(['name' => $ruolo, 'guard_name' => 'web']);
    $r->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    if ($impostazioni) {
        $r->givePermissionTo(Permission::firstOrCreate(['name' => Permesso::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    }
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($r);

    return $u;
}

function rigaNonPartita(object $s, ScritturaContabile $incasso, string $errore): RegistroInvio
{
    return RegistroInvio::create([
        'condominio_id' => $s->condominio->id, 'anagrafica_id' => $s->anna->id, 'tipo' => 'ricevuta', 'canale' => 'email',
        'documentabile_type' => ScritturaContabile::class, 'documentabile_id' => $incasso->id, 'numero' => $incasso->numero_protocollo,
        'destinatario' => $s->anna->email, 'dati' => ['bollo' => 'assolta', 'tipo_indirizzo' => 'email', 'soglia' => 'sopra'],
        'stato' => 'non_partita', 'errore' => $errore, 'emesso_il' => now(),
    ]);
}

const ERRORE_DI_AUTENTICAZIONE = 'Failed to authenticate on SMTP server with username "studio@smtp.example" using the following authenticators: "LOGIN". '
    . 'Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".".';

test('un messaggio accodato con il server di posta e partito quando la posta è tornata al trasporto log non scrive il PDF nel file di log', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    inviaUnaPosta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])
        ->assertOk()->assertJson(['in_coda' => 1]);
    $lavoro = Queue::pushed(SendQueuedNotifications::class)->sole();

    // Il servizio della coda rilegge le impostazioni prima di ogni messaggio: se nel frattempo la posta è stata disattivata, o la lettura delle
    // impostazioni è fallita, il trasporto diventa `log`.
    $file = storage_path('logs/prova-ricevuta-' . uniqid() . '.log');
    config(['logging.channels.prova_ricevuta' => ['driver' => 'single', 'path' => $file, 'level' => 'debug'], 'mail.mailers.log.channel' => 'prova_ricevuta', 'mail.default' => 'log']);
    app('mail.manager')->forgetMailers();

    $lavoro->handle(app(ChannelManager::class));

    $scritto = is_file($file) ? (string) file_get_contents($file) : '';
    @unlink($file);
    $riga = RegistroInvio::sole();
    expect($riga->stato)->toBe('non_partita')
        ->and($riga->errore)->toContain('Nessun server di posta')
        ->and($scritto)->not->toContain('application/pdf')
        ->and($scritto)->not->toContain($s->anna->email)
        ->and(MailLog::count())->toBe(0);
});

test('un campo che non è un testo (l\'indirizzo scritto a mano, la nota, gli estremi del bollo, i tipi di indirizzo) si rifiuta con 422 e non scrive niente', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $c = ['condominio' => $s->condominio->id];
    $u = amministratoreRicevute();
    $post = fn (string $rotta, array $campi) => $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.' . $rotta, $c), $campi)->status();

    $esiti = [
        'altro_indirizzo'  => inviaUnaPosta($this, $s, $incasso, ['destinatari' => [], 'altro_indirizzo' => ['spia@esterno.example'], 'bollo' => 'assolta'], $u)->status(),
        'nota'             => inviaUnaPosta($this, $s, $incasso, ['destinatari' => [$s->anna->id], 'nota' => ['x'], 'bollo' => 'assolta'], $u)->status(),
        'estremi'          => inviaUnaPosta($this, $s, $incasso, ['destinatari' => [$s->anna->id], 'modo_bollo' => 'virtuale', 'estremi_bollo' => ['x'], 'bollo' => 'assolta'], $u)->status(),
        'tipi_di_una'      => inviaUnaPosta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => [['email']]]], 'bollo' => 'assolta'], $u)->status(),
        'indirizzi'        => $post('anteprima', ['incassi' => [$incasso->id], 'indirizzi' => [['email']]]),
        'indirizzi_invio'  => $post('invia', ['incassi' => [$incasso->id], 'indirizzi' => [['email']], 'bollo_sopra' => 'assolta']),
        'nota_in_blocco'   => $post('invia', ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'nota' => ['x']]),
    ];
    $stampa = $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', $c), ['incassi' => [$incasso->id], 'bollo' => 'assolta', 'nota' => ['x']]);

    expect($esiti)->toBe(array_fill_keys(array_keys($esiti), 422))
        ->and($stampa->status())->toBe(422)
        ->and(RegistroInvio::count())->toBe(0);
    Notification::assertNothingSent();
});

test('l\'invio a un indirizzo scritto a mano ha un limite di dieci al minuto per utente; gli altri invii e gli altri utenti non lo sentono', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $u = amministratoreRicevute();
    $altro = amministratoreRicevute();

    $stati = collect(range(1, 12))->map(fn ($n) => inviaUnaPosta($this, $s, $incasso,
        ['destinatari' => [], 'altro_indirizzo' => "destinatario{$n}@esterno.example", 'bollo' => 'assolta'], $u)->status());

    // Senza un indirizzo scritto a mano il limite non conta, e un altro utente ha il suo conto.
    $senza = collect(range(1, 12))->map(fn () => inviaUnaPosta($this, $s, $incasso, ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'], $u)->status());
    $dopo = inviaUnaPosta($this, $s, $incasso, ['destinatari' => [], 'altro_indirizzo' => 'uno@esterno.example', 'bollo' => 'assolta'], $altro)->status();

    expect($stati->take(10)->unique()->values()->all())->toBe([200])
        ->and($stati->slice(10)->unique()->values()->all())->toBe([429])
        ->and($senza->unique()->values()->all())->toBe([200])
        ->and($dopo)->toBe(200);
});

test('il messaggio non porta gli identificativi del registro: le righe si legano a lui con un gettone casuale, e partito il messaggio diventano «inviata»', function () {
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    inviaUnaPosta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email', 'email_secondaria']]], 'bollo' => 'assolta'])->assertOk();

    $righe = RegistroInvio::orderBy('id')->get();
    $messaggi = app('mailer')->getSymfonyTransport()->messages();
    /** @var Email $email */
    $email = $messaggi[0]->getOriginalMessage();
    $intestazioni = collect($email->getHeaders()->all())->map(fn ($h) => $h->getName() . ': ' . $h->getBodyAsString())->implode("\n");

    expect($messaggi)->toHaveCount(2)
        ->and($intestazioni)->not->toContain('X-Registro-Invio')
        ->and($intestazioni)->not->toContain((string) $righe[0]->id . ',')
        ->and($righe->pluck('stato')->unique()->all())->toBe(['inviata'])
        ->and($righe->pluck('mail_log_id')->filter()->unique()->count())->toBe(2)
        // Un gettone per messaggio: uno solo per le righe di quel messaggio, diverso da un messaggio all'altro, e non un numero del registro.
        ->and($righe[0]->dati['gettone'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($righe[1]->dati['gettone'])->not->toBe($righe[0]->dati['gettone']);
});

test('la riga del registro si lega al messaggio che parte anche quando le ricevute di una persona viaggiano insieme in un messaggio solo', function () {
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $uno = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $due = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);

    $this->actingAs(amministratoreRicevute())->postJson(route('admin.gestionale.ricevute.invia', ['condominio' => $s->condominio->id]), [
        'incassi' => [$uno->id, $due->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'assolta',
    ])->assertOk()->assertJson(['messaggi' => 1, 'inviate' => 1]);

    $righe = RegistroInvio::orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and($righe->pluck('stato')->unique()->all())->toBe(['inviata'])
        ->and($righe->pluck('mail_log_id')->unique()->count())->toBe(1)
        ->and($righe[0]->dati['gettone'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($righe->pluck('dati.gettone')->unique()->count())->toBe(1);
});

test('l\'errore del server di posta: tutti leggono una frase semplice con il codice, l\'errore intero lo legge solo chi gestisce le impostazioni', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    rigaNonPartita($s, $incasso, ERRORE_DI_AUTENTICAZIONE);
    $collaboratore = utenteDelPannello('collaboratore', false);
    $chiGestisce = utenteDelPannello('amministratore', true);
    $c = ['condominio' => $s->condominio->id];

    $frase = 'Il server di posta non ha accettato il messaggio (codice 535).';
    $pagina = fn (User $u) => $this->actingAs($u)->get(route('admin.gestionale.ricevute.index', $c + ['ricevuta' => 'tutte']))->assertOk()->viewData('page')['props']['incassi']['data'][0]['ultima'];
    $incassoShow = fn (User $u) => $this->actingAs($u)->get(route('admin.gestionale.movimenti-rate.show', $c + ['scrittura' => $incasso->id]))->assertOk()->viewData('page')['props']['emissioni'][0];
    $finestra = fn (User $u) => $this->actingAs($u)->getJson(route('admin.gestionale.ricevute.opzioni', $c + ['scrittura' => $incasso->id]))->assertOk()->json('emissioni.0');

    foreach ([$collaboratore, $chiGestisce] as $u) {
        foreach ([$pagina($u), $incassoShow($u), $finestra($u)] as $emissione) {
            expect($emissione['errore'])->toBe($frase);
        }
    }
    foreach ([$pagina($collaboratore), $incassoShow($collaboratore), $finestra($collaboratore)] as $emissione) {
        expect(json_encode($emissione))->not->toContain('studio@smtp.example')->and($emissione['errore_completo'] ?? null)->toBeNull();
    }
    foreach ([$pagina($chiGestisce), $incassoShow($chiGestisce), $finestra($chiGestisce)] as $emissione) {
        expect($emissione['errore_completo'])->toBe(ERRORE_DI_AUTENTICAZIONE);
    }
});

test('la frase dell\'errore di posta: con il codice quando il server lo dà, senza quando non c\'è, e le frasi del programma restano com\'erano', function () {
    expect(ErroreDiPosta::frase(ERRORE_DI_AUTENTICAZIONE))->toBe('Il server di posta non ha accettato il messaggio (codice 535).')
        ->and(ErroreDiPosta::frase('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown".'))->toBe('Il server di posta non ha accettato il messaggio (codice 550).')
        ->and(ErroreDiPosta::frase('Connection could not be established with host "smtp.esempio.it:587": Connection refused'))->toBe('Il server di posta non ha accettato il messaggio.')
        ->and(ErroreDiPosta::frase('Il server ha rifiutato l\'autenticazione'))->toBe('Il server di posta non ha accettato il messaggio.')
        ->and(ErroreDiPosta::frase('L\'incasso è stato annullato prima dell\'invio: la ricevuta non è partita.'))->toBe('L\'incasso è stato annullato prima dell\'invio: la ricevuta non è partita.')
        ->and(ErroreDiPosta::frase(\App\Services\Gestionale\Ricevute\EmissioneRicevute::SENZA_SERVER_POSTA))->toBe(\App\Services\Gestionale\Ricevute\EmissioneRicevute::SENZA_SERVER_POSTA)
        ->and(ErroreDiPosta::frase(null))->toBeNull();
});

test('dopo un invio che il server rifiuta, la risposta e la prova danno la frase a tutti e l\'errore intero solo a chi gestisce le impostazioni', function () {
    trasportoCheRifiutaLeRicevute();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $collaboratore = utenteDelPannello('collaboratore', false);
    $chiGestisce = utenteDelPannello('amministratore', true);
    $campi = ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'];
    $prova = fn (User $u) => $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.prova', ['condominio' => $s->condominio->id]),
        ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'])->assertStatus(422)->json('message');

    $perTutti = inviaUnaPosta($this, $s, $incasso, $campi, $collaboratore)->assertOk()->json();
    $perChiGestisce = inviaUnaPosta($this, $s, $incasso, $campi, $chiGestisce)->assertOk()->json();

    expect($perTutti['errori'])->toBe(['Il server di posta non ha accettato il messaggio.'])
        ->and($perTutti)->not->toHaveKey('errori_completi')
        ->and($perChiGestisce['errori'])->toBe(['Il server di posta non ha accettato il messaggio.'])
        ->and($perChiGestisce['errori_completi'])->toBe(['Il server ha rifiutato l\'autenticazione'])
        ->and($prova($collaboratore))->toBe('La prova non è partita: il server di posta non ha accettato il messaggio.')
        ->and($prova($chiGestisce))->toBe('La prova non è partita: il server di posta non ha accettato il messaggio. Il server ha rifiutato l\'autenticazione');
});
