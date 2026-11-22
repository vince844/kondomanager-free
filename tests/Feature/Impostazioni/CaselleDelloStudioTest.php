<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Gestionale\RegistroInvio;
use App\Models\MailLog;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\Posta\CasellaPec;
use App\Services\Posta\ConfigurazionePec;
use App\Services\Posta\ErroreDellaPec;
use App\Services\Posta\MotivoDellaProva;
use App\Services\Posta\TrasportoPec;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\MailSettings;
use App\Settings\PecSettings;
use App\Settings\PrintSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/**
 * 1.12.0-beta.13 (decisioni 564, 569 e 572): la pagina «Configurazione email» diventa l'elenco delle caselle dello studio, la posta e la casella PEC.
 *
 * - La casella PEC sta nel suo gruppo di impostazioni e il programma la legge da un punto solo, `CasellaPec::configurazione()`, con la password cifrata
 *   come quella della posta, che non torna mai alla pagina e nel registro delle modifiche è «cambiata».
 * - Il programma ricorda l'ultima prova di ogni casella; un cambio di server, porta, cifratura, utente o password riporta la casella a «Da provare»; ogni
 *   prova è una riga dello storico delle email.
 * - La prova e il salvataggio della PEC passano dagli stessi controlli della posta: il permesso (403) prima dei campi (422), la password ridigitata (423),
 *   sei prove al minuto (429), la password salvata solo verso il suo server.
 * - Una prova non andata dice il motivo in parole semplici; «Manda le PEC» senza casella dice «Prima imposta la casella PEC dello studio» e porta qui.
 * - Dove la posta la prepara chi gestisce l'installazione la posta è di sola lettura; la PEC la imposta sempre lo studio.
 *
 * Che cosa non copre: un server PEC vero (il trasporto è finto); l'aspetto della pagina (vitest e la prova a video).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email' => 'andrea.costa@studiocosta.it', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    CdsTrasporto::$mandati = [];
    CdsTrasporto::$errore = null;
    app()->instance(TrasportoPec::class, new CdsTrasporto());
});

/** Il trasporto finto della PEC: ricorda i messaggi, o fallisce con il testo del server dato. */
final class CdsTrasporto implements TrasportoPec
{
    /** @var list<array{casella: ConfigurazionePec, messaggio: Email}> */
    public static array $mandati = [];

    public static ?string $errore = null;

    public function manda(ConfigurazionePec $casella, Email $messaggio): void
    {
        if (self::$errore !== null) {
            throw new TransportException(self::$errore);
        }
        self::$mandati[] = ['casella' => $casella, 'messaggio' => $messaggio];
    }
}

/** Andrea con la password ridigitata da poco (Coda 261). */
function cdsAndrea(object $test)
{
    return $test->actingAs($test->andrea)->withSession(['auth.password_confirmed_at' => time()]);
}

/** La casella PEC della scena 2 della bozza, come la manda la pagina. */
function cdsPec(array $cambi = []): array
{
    return array_merge(['indirizzo' => 'andrea.costa@pec.studiocosta.it', 'server' => 'smtps.pec.ilgestore.it', 'porta' => 465, 'cifratura' => 'ssl',
        'utente' => 'andrea.costa@pec.studiocosta.it', 'password' => 'segreto-pec'], $cambi);
}

function cdsCaselle(object $test): array
{
    return cdsAndrea($test)->get(route('impostazioni.mail'))->assertOk()->viewData('page')['props']['caselle'];
}

// ─── La configurazione letta da CasellaPec ────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('la casella PEC salvata dalla pagina è quella che il programma legge da CasellaPec, con la password in chiaro solo lì', function () {
    expect(app(CasellaPec::class)->configurazione())->toBeNull();

    cdsAndrea($this)->from(route('impostazioni.mail'))->post(route('admin.settings.pec.update'), cdsPec())->assertSessionHasNoErrors()->assertRedirect();

    $c = app(CasellaPec::class)->configurazione();
    expect($c)->toBeInstanceOf(ConfigurazionePec::class)
        ->and($c->indirizzo)->toBe('andrea.costa@pec.studiocosta.it')
        ->and($c->server)->toBe('smtps.pec.ilgestore.it')
        ->and($c->porta)->toBe(465)
        ->and($c->cifratura)->toBe('ssl')
        ->and($c->utente)->toBe('andrea.costa@pec.studiocosta.it')
        ->and($c->password())->toBe('segreto-pec')
        ->and(app(PecSettings::class)->refresh()->password)->not->toBe('segreto-pec')
        ->and(Crypt::decryptString(app(PecSettings::class)->password))->toBe('segreto-pec')
        ->and(app(CasellaPec::class)->impostata())->toBeTrue();
});

it('la cifratura della PEC è solo SSL o TLS, e i campi si dicono con le parole della pagina', function () {
    cdsAndrea($this)->from(route('impostazioni.mail'))->post(route('admin.settings.pec.update'), cdsPec(['cifratura' => 'null', 'server' => '']))
        ->assertSessionHasErrors(['cifratura', 'server']);

    expect(session('errors')->first('server'))->toContain('server di posta in uscita')
        ->and(app(CasellaPec::class)->configurazione())->toBeNull();
});

it('la password della PEC non torna mai alla pagina: «Impostata» o «Da impostare», nemmeno cifrata', function () {
    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec());
    $cifrata = app(PecSettings::class)->refresh()->password;

    $risposta = cdsAndrea($this)->get(route('impostazioni.mail'))->assertOk();
    $risposta->assertInertia(fn (Assert $p) => $p->component('impostazioni/impostazioniMail')
        ->where('caselle.pec.password_impostata', true)
        ->missing('caselle.pec.valori.password')
        ->where('caselle.pec.valori.server', 'smtps.pec.ilgestore.it'));
    expect($risposta->getContent())->not->toContain($cifrata)->not->toContain('segreto-pec');
});

it('salvata senza password, la PEC tiene quella di prima; con un altro server la password va riscritta, e non parte verso il server nuovo', function () {
    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec());

    cdsAndrea($this)->from(route('impostazioni.mail'))->post(route('admin.settings.pec.update'), cdsPec(['password' => '', 'indirizzo' => 'studio@pec.studiocosta.it']))
        ->assertSessionHasNoErrors();
    expect(app(CasellaPec::class)->configurazione()->password())->toBe('segreto-pec');

    cdsAndrea($this)->from(route('impostazioni.mail'))->post(route('admin.settings.pec.update'), cdsPec(['password' => '', 'server' => 'smtps.altrove.example']))
        ->assertSessionHasErrors(['password' => \App\Http\Controllers\Impostazioni\CasellaPecController::PASSWORD_DA_RISCRIVERE]);
    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['password' => '', 'server' => 'smtps.altrove.example', 'test_email' => 'a@pec.it']))
        ->assertStatus(422)->assertJsonPath('campo', 'password');

    expect(CdsTrasporto::$mandati)->toBe([])
        ->and(app(PecSettings::class)->refresh()->server)->toBe('smtps.pec.ilgestore.it');
});

// ─── La prova, «Da provare» dopo un cambio, lo storico ────────────────────────────────────────────────────────────────────────────────────────────────

it('la prova della PEC usa i valori scritti, va di serie alla stessa casella, è una riga dello storico, e la casella salvata diventa «Provata»', function () {
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-19 10:14', 'Europe/Rome'));
    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec());
    expect(cdsCaselle($this)['pec']['stato'])->toBe('da_provare')
        ->and(cdsCaselle($this)['pec']['adesso'])->toBe('Adesso: impostata il 19/11 alle 10:14, non ancora provata.');

    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['password' => '', 'test_email' => 'andrea.costa@pec.studiocosta.it']))
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('prova.quando', 'il 19/11 alle 10:14');

    expect(CdsTrasporto::$mandati)->toHaveCount(1)
        ->and(CdsTrasporto::$mandati[0]['casella']->password())->toBe('segreto-pec')
        ->and(CdsTrasporto::$mandati[0]['messaggio']->getTo()[0]->getAddress())->toBe('andrea.costa@pec.studiocosta.it')
        ->and(CdsTrasporto::$mandati[0]['messaggio']->getFrom()[0]->getAddress())->toBe('andrea.costa@pec.studiocosta.it');
    $log = MailLog::query()->latest('id')->first();
    expect($log->mailer)->toBe('pec')->and($log->status)->toBe('sent')->and($log->recipient)->toBe('andrea.costa@pec.studiocosta.it');

    $pec = cdsCaselle($this)['pec'];
    expect($pec['stato'])->toBe('provata')
        ->and($pec['parola'])->toBe('Provata')
        ->and($pec['adesso'])->toBe('Adesso: impostata, provata il 19/11 alle 10:14.')
        ->and($pec['riga'])->toBe('andrea.costa@pec.studiocosta.it · smtps.pec.ilgestore.it, porta 465, SSL')
        ->and($pec['card'])->toBe(['valore' => 'Provata', 'sotto' => 'andrea.costa@pec.studiocosta.it · il 19/11 alle 10:14']);
    \Illuminate\Support\Carbon::setTestNow();
});

it('cambiare server, porta, cifratura, utente o password riporta la PEC a «Da provare»; l\'indirizzo da solo no', function (array $cambio, string $stato) {
    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec());
    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['test_email' => 'andrea.costa@pec.studiocosta.it']))->assertOk();
    expect(cdsCaselle($this)['pec']['stato'])->toBe('provata');

    cdsAndrea($this)->from(route('impostazioni.mail'))->post(route('admin.settings.pec.update'), cdsPec($cambio))->assertSessionHasNoErrors();

    expect(cdsCaselle($this)['pec']['stato'])->toBe($stato);
})->with([
    'il server' => [['server' => 'smtps.altro.example'], 'da_provare'],
    'la porta' => [['porta' => 587, 'cifratura' => 'tls'], 'da_provare'],
    'l\'utente' => [['utente' => 'studio@pec.studiocosta.it'], 'da_provare'],
    'la password' => [['password' => 'nuova-pec'], 'da_provare'],
    'l\'indirizzo, da solo' => [['indirizzo' => 'segreteria@pec.studiocosta.it', 'password' => ''], 'provata'],
]);

it('una prova di valori mai salvati non dice niente della casella salvata; salvati, sì', function () {
    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec());
    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['password' => 'nuova-pec', 'test_email' => 'a@pec.it']))->assertOk();
    expect(cdsCaselle($this)['pec']['stato'])->toBe('da_provare');

    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec(['password' => 'nuova-pec']));
    expect(cdsCaselle($this)['pec']['stato'])->toBe('provata');
});

it('la posta: la prova ricordata la fa «Provata», un cambio dell\'utente la riporta a «Da provare», spenta è «Spenta»', function () {
    $posta = app(MailSettings::class);
    $posta->mail_enabled = true;
    $posta->mail_driver = 'smtp';
    $posta->mail_host = 'smtp.studiocosta.it';
    $posta->mail_port = 587;
    $posta->mail_username = 'segreteria@studiocosta.it';
    $posta->mail_password = Crypt::encryptString('segreto-posta');
    $posta->mail_encryption = 'tls';
    $posta->mail_from_address = 'segreteria@studiocosta.it';
    $posta->mail_from_name = 'Studio Costa, amministrazioni';
    $posta->save();
    $corpo = ['mail_enabled' => true, 'mail_driver' => 'smtp', 'mail_host' => 'smtp.studiocosta.it', 'mail_port' => 587, 'mail_username' => 'segreteria@studiocosta.it',
        'mail_password' => '', 'mail_encryption' => 'tls', 'mail_from_address' => 'segreteria@studiocosta.it', 'mail_from_name' => 'Studio Costa, amministrazioni'];
    expect(cdsCaselle($this)['posta']['stato'])->toBe('da_provare');

    cdsAndrea($this)->postJson(route('admin.settings.mail.test'), $corpo + ['test_email' => 'andrea.costa@studiocosta.it'])->assertOk();
    $p = cdsCaselle($this)['posta'];
    expect($p['stato'])->toBe('provata')
        ->and($p['adesso'])->toEndWith('Le email partono a nome di «Studio Costa, amministrazioni».')
        ->and($p['riga'])->toBe('segreteria@studiocosta.it · smtp.studiocosta.it, porta 587, TLS');

    cdsAndrea($this)->from(route('impostazioni.mail'))->post(route('admin.settings.mail.update'), ['mail_username' => 'altro@studiocosta.it'] + $corpo)
        ->assertSessionHasNoErrors();
    expect(cdsCaselle($this)['posta']['stato'])->toBe('da_provare');

    cdsAndrea($this)->from(route('impostazioni.mail'))->post(route('admin.settings.mail.update'), ['mail_enabled' => false] + $corpo)->assertSessionHasNoErrors();
    expect(cdsCaselle($this)['posta'])->toMatchArray(['stato' => 'spenta', 'parola' => 'Spenta',
        'adesso' => 'Adesso: spenta. Le email partono con la posta preparata sul server, se c\'è; se non c\'è, restano scritte nel registro del programma.']);
});

// ─── I motivi in parole ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('una prova non andata dice il motivo in parole semplici, il testo del server resta sotto, la riga «Adesso» lo ripete', function () {
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-19 10:02', 'Europe/Rome'));
    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec());
    CdsTrasporto::$errore = 'Failed to authenticate on SMTP server with username "andrea.costa@pec.studiocosta.it" using the following authenticators: '
        . '"LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".".';

    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['password' => '', 'test_email' => 'andrea.costa@pec.studiocosta.it']))
        ->assertStatus(500)
        ->assertJsonPath('motivo.titolo', 'Il gestore non ha accettato l\'utente o la password.')
        ->assertJsonPath('motivo.azione.etichetta', 'Riscrivi la password')
        ->assertJsonPath('message', CdsTrasporto::$errore);

    $pec = cdsCaselle($this)['pec'];
    expect($pec['stato'])->toBe('non_andata')
        ->and($pec['parola'])->toBe('Prova non andata')
        ->and($pec['adesso'])->toBe('Adesso: impostata, ma l\'ultima prova, il 19/11 alle 10:02, non è andata: il gestore non ha accettato la password.')
        ->and($pec['ultima_prova']['motivo']['fai'])->toBe('Riscrivi la password della casella PEC e riprova. Guarda anche l\'utente: di solito è l\'indirizzo PEC intero.')
        ->and($pec['ultima_prova']['testo'])->toContain('535 5.7.8 Error: authentication failed');
    $log = MailLog::query()->latest('id')->first();
    expect($log->mailer)->toBe('pec')->and($log->status)->toBe('failed')->and($log->error_message)->toContain('535');
    \Illuminate\Support\Carbon::setTestNow();
});

it('i sei motivi della scena 3, e tutto il resto', function (string $server, string $chiave, string $titolo) {
    $motivo = MotivoDellaProva::per(ErroreDellaPec::motivo($server), 'pec');

    expect($motivo['chiave'])->toBe($chiave)->and($motivo['titolo'])->toBe($titolo);
})->with([
    'la password' => ['Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".', MotivoDellaProva::ACCESSO,
        'Il gestore non ha accettato l\'utente o la password.'],
    'la porta chiusa' => ['Connection could not be established with host "ssl://smtps.pec.ilgestore.it:465": stream_socket_client(): Unable to connect to '
        . 'ssl://smtps.pec.ilgestore.it:465 (Connection refused)', MotivoDellaProva::RIFIUTATA, 'Il server non accetta la connessione su questa porta.'],
    'il tempo' => ['Connection to "smtps.pec.ilgestore.it:465" timed out.', MotivoDellaProva::TEMPO, 'Il server non ha risposto in tempo.'],
    'il nome' => ['Connection could not be established with host "ssl://smtps.pec.ilgestor.it:465": stream_socket_client(): php_network_getaddresses: '
        . 'getaddrinfo for smtps.pec.ilgestor.it failed: No such host is known', MotivoDellaProva::NOME, 'Il server con questo nome non esiste.'],
    'il mittente' => ['Expected response code "250/251/252" but got code "553", with message "553 5.7.1 <x@pec.it>: Sender address rejected".',
        MotivoDellaProva::MITTENTE, 'Il gestore accetta solo la posta che parte dall\'indirizzo della casella.'],
    'il certificato' => ['stream_socket_enable_crypto(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed',
        MotivoDellaProva::CERTIFICATO, 'La connessione sicura non si è aperta.'],
    'tutto il resto' => ['Expected response code "354" but got code "554", with message "554 5.6.0 Message rejected".', MotivoDellaProva::ALTRO,
        'Il server ha rifiutato la prova.'],
]);

// ─── I controlli di oggi ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('senza il permesso delle impostazioni salvare e provare la PEC è 403, prima dei campi sbagliati (422)', function (Role $ruolo) {
    $chi = User::factory()->create(['email_verified_at' => now()]);
    $chi->assignRole($ruolo->value);

    $this->actingAs($chi)->withSession(['auth.password_confirmed_at' => time()])->postJson(route('admin.settings.pec.update'), ['cifratura' => 'nessuna'])
        ->assertForbidden();
    $this->actingAs($chi)->withSession(['auth.password_confirmed_at' => time()])->postJson(route('admin.settings.pec.prova'), ['cifratura' => 'nessuna'])
        ->assertForbidden();
    $this->actingAs($chi)->get(route('impostazioni.mail'))->assertForbidden();

    expect(CdsTrasporto::$mandati)->toBe([])->and(app(PecSettings::class)->refresh()->server)->toBeNull();
})->with([
    'collaboratore' => [Role::COLLABORATORE],
    'condòmino' => [Role::UTENTE],
    'fornitore' => [Role::FORNITORE],
]);

it('l\'amministratore con i campi sbagliati riceve 422, e senza la password ridigitata 423, senza che niente cambi o parta', function () {
    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), ['cifratura' => 'nessuna'])->assertStatus(422)
        ->assertJsonValidationErrors(['indirizzo', 'server', 'porta', 'cifratura', 'utente', 'test_email']);
    // La sessione resta fra una richiesta e l'altra del test: la conferma si toglie.
    $this->actingAs($this->andrea)->withSession(['auth.password_confirmed_at' => 0])->postJson(route('admin.settings.pec.update'), cdsPec())->assertStatus(423);
    $this->actingAs($this->andrea)->withSession(['auth.password_confirmed_at' => 0])->postJson(route('admin.settings.pec.prova'), cdsPec(['test_email' => 'a@pec.it']))
        ->assertStatus(423);

    expect(app(PecSettings::class)->refresh()->server)->toBeNull()->and(CdsTrasporto::$mandati)->toBe([]);
});

it('sei prove della PEC al minuto, come per la posta: la settima risponde 429 e non parte', function () {
    for ($i = 0; $i < 6; $i++) {
        cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['test_email' => 'a@pec.it']))->assertOk();
    }
    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['test_email' => 'a@pec.it']))->assertStatus(429);

    expect(CdsTrasporto::$mandati)->toHaveCount(6);
});

// ─── Il registro delle modifiche ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('salvare la PEC è una riga della «Storia» della pagina, con la password «cambiata» e senza valori; la prova non scrive righe', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec(['porta' => 587, 'cifratura' => 'tls']))->assertSessionHasNoErrors();
    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['porta' => 587, 'cifratura' => 'tls', 'test_email' => 'a@pec.it']))->assertOk();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->get();
    $campi = collect($righe[0]->campi)->keyBy('chiave');
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->oggetto_tipo)->toBe('impostazioni_posta')
        ->and(\App\Services\RegistroModifiche\SchedeDellaStoria::righe('impostazioni_posta', null)->pluck('id')->all())->toContain($righe[0]->id)
        ->and($righe[0]->oggetto_nome)->toBe('Casella PEC dello studio')
        ->and($campi['server'])->toMatchArray(['etichetta' => 'Server di posta in uscita', 'dopo' => 'smtps.pec.ilgestore.it'])
        ->and($campi['cifratura'])->toMatchArray(['etichetta' => 'Cifratura', 'prima' => 'SSL', 'dopo' => 'TLS'])
        ->and($campi['porta'])->toMatchArray(['etichetta' => 'Porta', 'prima' => '465', 'dopo' => '587'])
        ->and($campi['password'])->toMatchArray(['etichetta' => 'Password', 'dopo' => 'cambiata', 'valore_dopo' => null, 'segreto' => true])
        ->and($campi->has('impostata_il'))->toBeFalse()
        ->and(json_encode($righe[0]->campi))->not->toContain('segreto-pec');
});

// ─── Le proposte ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('la PEC da impostare: le frasi della scena 1, l\'indirizzo proposto dalla carta dello studio, la porta 465 con SSL', function () {
    $carta = app(PrintSettings::class);
    $carta->studio_pec = 'andrea.costa@pec.studiocosta.it';
    $carta->save();

    $pec = cdsCaselle($this)['pec'];
    expect($pec)->toMatchArray([
        'stato' => 'da_impostare',
        'parola' => 'Da impostare',
        'adesso' => 'Adesso: da impostare. Le convocazioni e le lettere per PEC non possono ancora partire dal programma.',
        'card' => ['valore' => 'Da impostare', 'sotto' => 'le convocazioni e le lettere per PEC partono da qui'],
        'password_impostata' => false,
        'dalla_carta' => 'andrea.costa@pec.studiocosta.it',
    ])->and($pec['valori'])->toMatchArray(['porta' => 465, 'cifratura' => 'ssl', 'indirizzo' => null]);
});

it('la card degli invii del mese conta dallo storico delle email e dal registro degli invii', function () {
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-19 12:00', 'Europe/Rome'));
    MailLog::create(['recipient' => 'a@x.it', 'subject' => 'Rata', 'mailer' => 'smtp', 'status' => 'sent', 'sent_at' => now()]);
    MailLog::create(['recipient' => 'b@x.it', 'subject' => 'Rata', 'mailer' => 'smtp', 'status' => 'sent', 'sent_at' => now()]);
    MailLog::create(['recipient' => 'c@x.it', 'subject' => 'Rata', 'mailer' => 'smtp', 'status' => 'failed', 'sent_at' => now()]);
    MailLog::create(['recipient' => 'd@x.it', 'subject' => 'Rata', 'mailer' => 'smtp', 'status' => 'sent', 'sent_at' => now()->subMonth()]);

    $invii = cdsAndrea($this)->get(route('impostazioni.mail'))->viewData('page')['props']['invii'];

    expect($invii)->toMatchArray(['titolo' => 'Gli invii di novembre', 'valore' => '2 email', 'sotto' => '1 non partita · nessuna PEC'])
        ->and($invii['url'])->toBe(route('logs.index'));
    \Illuminate\Support\Carbon::setTestNow();
});

// ─── La posta dell'installazione ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('dove la posta la prepara chi gestisce l\'installazione la posta è di sola lettura (403), la PEC si imposta lo stesso', function () {
    config(['kondomanager.posta_dell_installazione' => true]);

    $caselle = cdsCaselle($this);
    expect($caselle['posta'])->toMatchArray(['stato' => 'gia_pronta', 'parola' => 'Già pronta', 'sola_lettura' => true])
        ->and(\App\Support\FunzioniInstallazione::mappa()['posta_dell_installazione'])->toBeTrue();
    cdsAndrea($this)->post(route('admin.settings.mail.update'), ['mail_enabled' => true, 'mail_driver' => 'sendmail', 'mail_from_address' => 'x@x.it'])
        ->assertForbidden();
    cdsAndrea($this)->postJson(route('admin.settings.mail.test'), ['mail_driver' => 'sendmail', 'test_email' => 'x@x.it'])->assertForbidden();
    cdsAndrea($this)->post(route('admin.settings.pec.update'), cdsPec())->assertSessionHasNoErrors();

    expect(app(CasellaPec::class)->impostata())->toBeTrue();
});

it('di serie la chiave è spenta e la posta si imposta come sempre', function () {
    expect(\App\Support\FunzioniInstallazione::mappa()['posta_dell_installazione'])->toBeFalse()
        ->and(cdsCaselle($this)['posta']['sola_lettura'])->toBeFalse();
});

it('la PEC partita conta fra gli invii del mese', function () {
    cdsAndrea($this)->postJson(route('admin.settings.pec.prova'), cdsPec(['test_email' => 'a@pec.it']))->assertOk();

    expect(cdsAndrea($this)->get(route('impostazioni.mail'))->viewData('page')['props']['invii']['sotto'])->toBe('nessuna non partita · 1 PEC')
        ->and(RegistroInvio::query()->count())->toBe(0);
});
