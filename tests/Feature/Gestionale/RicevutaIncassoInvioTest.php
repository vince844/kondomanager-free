<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RegistroInvio;
use App\Models\MailLog;
use App\Notifications\Gestionale\RicevutaIncassoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * L'invio della ricevuta per email (decisione 200 e le aggiunte del 05/11/2026): una notifica in coda con il PDF allegato, a ogni indirizzo che
 * l'amministratore spunta (primaria e secondaria proposte, la PEC no), non agli indirizzi delle altre notifiche. Una riga del registro per
 * ricevuta e indirizzo, che nasce `in_coda`; il registro delle email la porta `inviata` quando il server di posta accetta il messaggio, con il
 * legame `mail_log_id`, o `non_partita` con l'errore. L'elenco dei destinatari lo propone il server, che rifiuta chi non è fra quelli proposti.
 * Nell'invio in blocco le ricevute della stessa persona partono in un messaggio solo per indirizzo, al più 50 per messaggio.
 *
 * Che cosa NON copre: la consegna (il programma sa solo che il server ha accettato), il testo dell'email a video.
 */
function inviaUnaRicevuta($test, object $s, $incasso, array $campi, $utente = null)
{
    return $test->actingAs($utente ?? amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]), $campi);
}

function inviaRicevuteInBlocco($test, object $s, array $campi, $utente = null)
{
    return $test->actingAs($utente ?? amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.invia', ['condominio' => $s->condominio->id]), $campi);
}

test('l\'invio accoda una notifica per ogni indirizzo spuntato, con il PDF allegato e il suo nome, e una riga del registro per indirizzo', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [$s->anna->id], 'bollo' => 'assolta', 'nota' => 'Grazie'])
        ->assertOk()
        ->assertJson(['persone' => 1, 'ricevute' => 1, 'messaggi' => 2, 'in_coda' => 2, 'senza_indirizzo' => 0]);

    $righe = RegistroInvio::orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and($righe->pluck('destinatario')->all())->toBe([$s->anna->email, $s->anna->email_secondaria])
        ->and($righe->map(fn ($r) => $r->dati['tipo_indirizzo'])->all())->toBe(['email', 'email_secondaria'])
        ->and($righe->pluck('stato')->unique()->all())->toBe(['in_coda'])
        ->and($righe->pluck('anagrafica_id')->unique()->all())->toBe([$s->anna->id])
        ->and($righe[0]->canale)->toBe('email')
        ->and($righe[0]->dati)->toMatchArray(['bollo' => 'assolta', 'nota' => 'Grazie']);

    // Il messaggio va all'indirizzo scelto, non alla persona: niente passa da `Anagrafica::routeNotificationForMail`.
    Notification::assertNotSentTo($s->anna, RicevutaIncassoNotification::class);
    $notifica = notificheRicevutaA($s->anna->email)->sole();
    expect($notifica->invii)->toBe([$righe[0]->id])
        ->and($notifica)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and(notificheRicevutaA($s->anna->email_secondaria)->sole()->invii)->toBe([$righe[1]->id]);

    $messaggio = $notifica->toMail(new AnonymousNotifiable);
    expect($messaggio->rawAttachments)->toHaveCount(1)
        ->and($messaggio->rawAttachments[0]['name'])->toContain(strtolower($incasso->numero_protocollo))
        ->and($messaggio->rawAttachments[0]['name'])->toEndWith('.pdf')
        ->and($messaggio->rawAttachments[0]['options']['mime'])->toBe('application/pdf')
        ->and(str_starts_with($messaggio->rawAttachments[0]['data'], '%PDF'))->toBeTrue()
        ->and($righe[0]->fresh()->impronta)->toBe(hash('sha256', $messaggio->rawAttachments[0]['data']))
        ->and($messaggio->subject)->toBe('Condominio Via dei Pini — Ricevuta n. ' . $incasso->numero_protocollo . ' del 01/02/2026')
        ->and($messaggio->greeting)->toBe('Gentile Anna Bruni,')
        ->and(implode(' ', $messaggio->introLines))->toContain('€ 150,00')
        ->and(implode(' ', $messaggio->introLines))->toContain('01/02/2026')
        ->and(implode(' ', $messaggio->introLines))->not->toContain('Rata');
});

test('partita davvero: il registro delle email porta ogni riga a «inviata», con il legame al suo messaggio, e il messaggio ha l\'allegato', function () {
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])
        ->assertOk()->assertJson(['inviate' => 1, 'messaggi' => 1]);

    $riga = RegistroInvio::sole();
    $log = MailLog::sole();
    expect($riga->stato)->toBe('inviata')
        ->and($riga->mail_log_id)->toBe($log->id)
        ->and($log->status)->toBe('sent')
        ->and($log->recipient)->toBe($s->anna->email);

    /** @var Email $email */
    $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
    expect($email->getAttachments())->toHaveCount(1)
        ->and(collect($email->getTo())->map->getAddress()->all())->toBe([$s->anna->email])
        // Il legame con il messaggio è il gettone della riga, non un'intestazione: a chi riceve non arriva niente del registro.
        ->and($riga->dati['gettone'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($email->getHeaders()->has('X-Registro-Invio'))->toBeFalse();
});

// Senza server di posta il trasporto `log` scriverebbe il messaggio intero, PDF compreso, nel file di log: le ricevute non si accodano e il
// programma lo dice subito. Prima di questa regola la riga nasceva e restava «non partita» con il legame alla riga `logged` del registro delle email.
test('senza server di posta (trasporto log) l\'invio si rifiuta subito e lo dice: niente nel registro, niente nel registro delle email', function () {
    config(['mail.default' => 'log']);
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $u = amministratoreRicevute();
    $c = ['condominio' => $s->condominio->id];

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'], $u)
        ->assertStatus(422)->assertJsonFragment(['message' => EmissioneRicevute::SENZA_SERVER_POSTA]);
    inviaRicevuteInBlocco($this, $s, ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'], $u)->assertStatus(422);
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.prova', $c), ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'])
        ->assertStatus(422)->assertJsonFragment(['message' => EmissioneRicevute::SENZA_SERVER_POSTA]);

    expect(RegistroInvio::count())->toBe(0)
        ->and(MailLog::count())->toBe(0)
        ->and($this->actingAs($u)->getJson(route('admin.gestionale.ricevute.opzioni', $c + ['scrittura' => $incasso->id]))->json('senza_server_posta'))->toBeTrue()
        ->and($this->actingAs($u)->postJson(route('admin.gestionale.ricevute.anteprima', $c), ['incassi' => [$incasso->id]])->json('senza_server_posta'))->toBeTrue();

    // Una riga non partita di prima: «Riprova» e «invia di nuovo» lo dicono allo stesso modo.
    $riga = RegistroInvio::create(['condominio_id' => $s->condominio->id, 'anagrafica_id' => $s->anna->id, 'tipo' => 'ricevuta', 'canale' => 'email',
        'documentabile_type' => \App\Models\Gestionale\ScritturaContabile::class, 'documentabile_id' => $incasso->id, 'numero' => $incasso->numero_protocollo,
        'destinatario' => $s->anna->email, 'dati' => ['bollo' => 'assolta', 'soglia' => 'sopra', 'tipo_indirizzo' => 'email'], 'stato' => 'non_partita', 'emesso_il' => now()]);
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.riprova', $c))->assertStatus(422);
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia-di-nuovo', $c + ['invio' => $riga->id]))->assertStatus(422);
    expect(RegistroInvio::count())->toBe(1);
});

test('un invio che fallisce porta la riga a «non partita» con l\'errore, e la risposta lo conta', function () {
    trasportoCheRifiutaLeRicevute();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'])->assertOk()->assertJson(['non_partite' => 2]);

    expect(RegistroInvio::pluck('stato')->unique()->all())->toBe(['non_partita'])
        ->and(RegistroInvio::first()->errore)->toContain('rifiutato l\'autenticazione');
});

test('un destinatario che il server non ha proposto per quell\'incasso è rifiutato, e niente parte', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [$s->carlo->id], 'bollo' => 'assolta'])->assertStatus(422);
    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [], 'bollo' => 'assolta'])->assertStatus(422);
    // Un tipo di indirizzo che la persona non ha.
    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['pec']]], 'bollo' => 'assolta'])->assertStatus(422);

    Notification::assertNothingSent();
    expect(RegistroInvio::count())->toBe(0);
});

test('i tre indirizzi: primaria e secondaria proposte, la PEC no; chi ha versato non spuntato; la PEC si spunta e la riga lo dice', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $s->anna->update(['pec' => 'anna.bruni@pec.prova.it']);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['versato_da_id' => $s->carlo->id]);

    $opzioni = $this->actingAs(amministratoreRicevute())
        ->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))
        ->assertOk()->json();

    expect(collect($opzioni['destinatari'])->map(fn ($d) => [$d['anagrafica_id'], $d['ruolo']])->all())
        ->toBe([[$s->anna->id, 'posizione'], [$s->carlo->id, 'versato_da']])
        ->and($opzioni['destinatari'][0]['indirizzi'])->toBe([
            ['tipo' => 'email', 'indirizzo' => $s->anna->email, 'proposto' => true, 'chiave' => 'email'],
            ['tipo' => 'email_secondaria', 'indirizzo' => $s->anna->email_secondaria, 'proposto' => true, 'chiave' => 'email_secondaria'],
            ['tipo' => 'pec', 'indirizzo' => 'anna.bruni@pec.prova.it', 'proposto' => false, 'chiave' => 'pec'],
        ])
        ->and($opzioni['destinatari'][1]['indirizzi'])->toBe([['tipo' => 'email', 'indirizzo' => $s->carlo->email, 'proposto' => false, 'chiave' => 'email']]);

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['pec']], ['anagrafica_id' => $s->carlo->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])
        ->assertOk()->assertJson(['persone' => 2, 'messaggi' => 2]);

    expect(notificheRicevutaA('anna.bruni@pec.prova.it'))->toHaveCount(1)
        ->and(notificheRicevutaA($s->carlo->email))->toHaveCount(1)
        ->and(notificheRicevutaA($s->anna->email))->toHaveCount(0)
        ->and(RegistroInvio::orderBy('id')->get()->map(fn ($r) => [$r->anagrafica_id, $r->dati['tipo_indirizzo']])->all())
        ->toBe([[$s->anna->id, 'pec'], [$s->carlo->id, 'email']]);
});

test('una persona senza email non si può scegliere; l\'anteprima del blocco la conta fra quelle da stampare, e l\'invio non le manda niente', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);
    $anna = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    inviaUnaRicevuta($this, $s, $ugo, ['destinatari' => [$s->ugo->id], 'bollo' => 'non_dovuta'])->assertStatus(422)->assertJsonFragment(['message' => 'Ugo Neri non ha un indirizzo email: stampala per la posta.']);

    $anteprima = $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$ugo->id, $anna->id], 'indirizzi' => ['email', 'email_secondaria']])
        ->assertOk()->json();
    expect($anteprima['ricevute'])->toBe(2)
        // `messaggi_adesso`: quanti partono davvero con il tetto (qui nessun tetto: tutti).
        ->and($anteprima['email'])->toBe(['persone' => 1, 'ricevute' => 1, 'indirizzi' => 2, 'messaggi' => 2, 'messaggi_adesso' => 2])
        ->and($anteprima['senza_indirizzo'])->toBe(['ricevute' => 1, 'incassi' => [$ugo->id], 'persone' => ['Ugo Neri']])
        ->and($anteprima['sopra_soglia'])->toBe(1)
        ->and($anteprima['sotto_soglia'])->toBe(1);

    inviaRicevuteInBlocco($this, $s, ['incassi' => [$ugo->id, $anna->id], 'indirizzi' => ['email', 'email_secondaria'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta'])
        ->assertOk()
        ->assertJson(['persone' => 1, 'ricevute' => 1, 'messaggi' => 2, 'in_coda' => 2, 'senza_indirizzo' => 1, 'senza_indirizzo_incassi' => [$ugo->id]]);

    expect(RegistroInvio::count())->toBe(2);
});

test('chi ha solo la PEC resta fuori se la PEC non è scelta, e l\'anteprima lo dice; con la PEC scelta la riceve', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $s->ugo->update(['pec' => 'ugo.neri@pec.prova.it']);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);
    $anteprima = fn (array $tipi) => $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$ugo->id], 'indirizzi' => $tipi])->json();

    expect($anteprima(['email', 'email_secondaria'])['solo_pec'])->toBe(1)
        ->and($anteprima(['email', 'email_secondaria'])['senza_indirizzo']['ricevute'])->toBe(1)
        ->and($anteprima(['email', 'pec'])['email'])->toBe(['persone' => 1, 'ricevute' => 1, 'indirizzi' => 1, 'messaggi' => 1, 'messaggi_adesso' => 1]);

    inviaRicevuteInBlocco($this, $s, ['incassi' => [$ugo->id], 'indirizzi' => ['email', 'pec'], 'bollo_sotto' => 'non_dovuta'])->assertOk()->assertJson(['messaggi' => 1]);
    expect(notificheRicevutaA('ugo.neri@pec.prova.it'))->toHaveCount(1)
        ->and(RegistroInvio::sole()->dati)->toMatchArray(['tipo_indirizzo' => 'pec', 'tipi_scelti' => ['email', 'pec']]);
});

test('nell\'invio in blocco le ricevute della stessa persona partono in un messaggio solo per indirizzo, e le righe di quel messaggio hanno lo stesso mail_log_id', function () {
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $prima = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $seconda = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 50.00]], '2026-03-01', 50.00);

    inviaRicevuteInBlocco($this, $s, ['incassi' => [$prima->id, $seconda->id], 'indirizzi' => ['email', 'email_secondaria'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta'])
        ->assertOk()->assertJson(['persone' => 1, 'ricevute' => 2, 'messaggi' => 2, 'inviate' => 2]);

    $messaggi = app('mailer')->getSymfonyTransport()->messages();
    expect($messaggi)->toHaveCount(2)
        ->and($messaggi[0]->getOriginalMessage()->getAttachments())->toHaveCount(1)
        ->and($messaggi[0]->getOriginalMessage()->getSubject())->toBe('Condominio Via dei Pini — Ricevute di pagamento (2)');

    $righe = RegistroInvio::orderBy('id')->get();
    expect($righe)->toHaveCount(4)
        ->and($righe->pluck('stato')->unique()->all())->toBe(['inviata'])
        ->and(MailLog::count())->toBe(2);
    foreach ($righe->groupBy('destinatario') as $indirizzo => $suoi) {
        expect($suoi)->toHaveCount(2)
            ->and($suoi->pluck('mail_log_id')->unique())->toHaveCount(1)
            ->and(MailLog::find($suoi->first()->mail_log_id)->recipient)->toBe($indirizzo);
    }
    expect($righe->firstWhere('documentabile_id', $prima->id)->dati['bollo'])->toBe('assolta')
        ->and($righe->firstWhere('documentabile_id', $seconda->id)->dati['bollo'])->toBe('non_dovuta');
});

test('sopra il limite di ricevute per messaggio la stessa persona riceve più messaggi', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $ids = [];
    for ($i = 1; $i <= EmissioneRicevute::RICEVUTE_PER_MESSAGGIO + 1; $i++) {
        $ids[] = incassaPerRicevuta($s, $s->anna, [[$s->anna3->fresh(), 1.00]], '2026-02-01', 1.00)->id;
    }

    inviaRicevuteInBlocco($this, $s, ['incassi' => $ids, 'indirizzi' => ['email'], 'bollo_sotto' => 'non_dovuta'])
        ->assertOk()->assertJson(['persone' => 1, 'ricevute' => EmissioneRicevute::RICEVUTE_PER_MESSAGGIO + 1, 'messaggi' => 2]);

    expect(notificheRicevutaA($s->anna->email)->map(fn ($n) => count($n->invii))->all())->toBe([EmissioneRicevute::RICEVUTE_PER_MESSAGGIO, 1]);
});

test('un incasso annullato non si invia: rifiutato da solo, saltato nel blocco', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'])->assertStatus(422);
    inviaRicevuteInBlocco($this, $s, ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'])->assertOk()->assertJson(['ricevute' => 0, 'annullati' => 1]);

    Notification::assertNothingSent();
    expect(RegistroInvio::count())->toBe(0);
});

test('un altro indirizzo, scritto nella finestra di un incasso, riceve la ricevuta e finisce nel registro come destinatario', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [], 'altro_indirizzo' => 'studio.legale@prova.it', 'bollo' => 'assolta'])
        ->assertOk()->assertJson(['in_coda' => 1]);

    expect(notificheRicevutaA('studio.legale@prova.it'))->toHaveCount(1);
    Notification::assertSentTimes(RicevutaIncassoNotification::class, 1);

    $riga = RegistroInvio::sole();
    expect($riga->destinatario)->toBe('studio.legale@prova.it')
        ->and($riga->anagrafica_id)->toBe($s->anna->id)
        ->and($riga->dati['tipo_indirizzo'])->toBe('altro');

    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [], 'altro_indirizzo' => 'non-è-un-indirizzo', 'bollo' => 'assolta'])->assertStatus(422);
});

test('«invia di nuovo» rimanda la stessa ricevuta con le stesse scelte allo stesso indirizzo e scrive una riga nuova; per un incasso annullato si rifiuta', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'da_assolvere', 'nota' => 'Prima copia'])->assertOk();
    $prima = RegistroInvio::sole();
    $prima->update(['stato' => 'non_partita', 'errore' => 'Connessione rifiutata']);

    $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.invia-di-nuovo', ['condominio' => $s->condominio->id, 'invio' => $prima->id]))
        ->assertOk()->assertJson(['in_coda' => 1]);

    $nuova = RegistroInvio::orderByDesc('id')->first();
    expect($nuova->id)->not->toBe($prima->id)
        ->and($nuova->stato)->toBe('in_coda')
        ->and($nuova->dati)->toMatchArray(['bollo' => 'da_assolvere', 'nota' => 'Prima copia', 'tipo_indirizzo' => 'email'])
        ->and($nuova->destinatario)->toBe($prima->destinatario);
    expect(notificheRicevutaA($s->anna->email))->toHaveCount(2);

    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);
    $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.invia-di-nuovo', ['condominio' => $s->condominio->id, 'invio' => $prima->id]))
        ->assertStatus(422);
});

test('«Riprova le non partite» rimanda solo le ricevute la cui ultima emissione a quell\'indirizzo non è partita', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'])->assertOk();
    RegistroInvio::where('destinatario', $s->anna->email)->update(['stato' => 'non_partita', 'errore' => 'Casella piena']);
    RegistroInvio::where('destinatario', $s->anna->email_secondaria)->update(['stato' => 'inviata']);

    $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.riprova', ['condominio' => $s->condominio->id]))
        ->assertOk()->assertJson(['messaggi' => 1, 'in_coda' => 1]);

    expect(RegistroInvio::count())->toBe(3)
        ->and(RegistroInvio::orderByDesc('id')->first()->destinatario)->toBe($s->anna->email);

    // Al secondo giro l'ultima emissione a quell'indirizzo è in coda: niente da riprovare.
    $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.riprova', ['condominio' => $s->condominio->id]))
        ->assertOk()->assertJson(['messaggi' => 0]);
});

test('«Mandane una a me» manda il messaggio della prima persona all\'utente che lavora, e non scrive il registro', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $anna = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);

    $this->actingAs($utente)
        ->postJson(route('admin.gestionale.ricevute.prova', ['condominio' => $s->condominio->id]), ['incassi' => [$ugo->id, $anna->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta'])
        ->assertOk()->assertJson(['indirizzo' => $utente->email, 'persona' => 'Anna Bruni', 'ricevute' => 1]);

    $prova = notificheRicevutaA($utente->email)->sole();
    expect($prova->invii)->toBe([])
        ->and(RegistroInvio::count())->toBe(0);

    $messaggio = $prova->toMail(new AnonymousNotifiable);
    expect($messaggio->subject)->toStartWith('Prova — Condominio Via dei Pini — Ricevuta n. ' . $anna->numero_protocollo)
        ->and($messaggio->rawAttachments)->toHaveCount(1)
        ->and(RegistroInvio::count())->toBe(0);
});

test('la riga del registro di un altro condominio non si rimanda: 404', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $altro = scenarioRicevutaIncasso('Condominio Corso Italia');
    $suo = incassaPerRicevuta($altro, $altro->anna, [[$altro->anna1, 150.00]], '2026-02-01', 150.00);
    inviaUnaRicevuta($this, $altro, $suo, ['destinatari' => [['anagrafica_id' => $altro->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();

    $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.invia-di-nuovo', ['condominio' => $s->condominio->id, 'invio' => RegistroInvio::sole()->id]))
        ->assertNotFound();
});

test('la finestra di un incasso ripropone il bollo e gli estremi scelti l\'ultima volta in quel condominio da chi la apre, e la prima volta niente; la nota no', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $sopra = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $sotto = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);
    $opzioni = fn ($incasso, $chi) => $this->actingAs($chi)
        ->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->json('scelta_ricordata');
    // Il modo del bollo e la causale si ricordano come il bollo (decisione 202); gli estremi solo del modo virtuale, che valgono per tutte. La nota
    // non si ricorda (decisione 204): una nota scritta per una persona era finita sulla ricevuta di un'altra, e in blocco su tutte.
    $vuota = ['bollo_sopra' => null, 'bollo_sotto' => null, 'modo_bollo' => null, 'estremi_bollo' => null, 'stampa_causale' => null, 'indirizzi' => null];

    expect($opzioni($sopra, $utente))->toBe($vuota);

    $this->actingAs($utente)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$sotto->id], 'bollo' => 'non_dovuta', 'nota' => 'Vecchia']);
    inviaRicevuteInBlocco($this, $s, ['incassi' => [$sopra->id], 'indirizzi' => ['email', 'pec'], 'bollo_sopra' => 'assolta', 'modo_bollo' => 'virtuale',
        'estremi_bollo' => 'autorizzazione n. 0123', 'nota' => 'Ricevuta di febbraio', 'stampa_causale' => 1], $utente);

    expect($opzioni($sopra, $utente))->toBe(['bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'autorizzazione n. 0123',
        'stampa_causale' => true, 'indirizzi' => ['email', 'pec']])
        ->and($opzioni($sopra, amministratoreRicevute()))->toBe($vuota);
});

test('l\'anteprima del blocco conta le già inviate, i versamenti di più di 60 giorni fa, chi non ha un indirizzo postale; l\'invio può escludere le già inviate', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $s->anna->update(['indirizzo' => '']);
    $recente = now()->setTimezone(config('app.user_timezone'))->subDays(5)->format('Y-m-d');
    $gia = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], $recente, 150.00);
    $nuova = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-01-10', 120.50);
    inviaUnaRicevuta($this, $s, $gia, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);

    $anteprima = $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$gia->id, $nuova->id], 'indirizzi' => ['email']])
        ->assertOk()->json();
    expect($anteprima['gia_inviate'])->toBe(['ricevute' => 1, 'incassi' => [$gia->id]])
        ->and($anteprima['piu_vecchie'])->toBe(1)
        ->and($anteprima['senza_indirizzo_postale'])->toBe(['Anna Bruni']);

    inviaRicevuteInBlocco($this, $s, ['incassi' => [$gia->id, $nuova->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'escludi_gia_inviate' => 1])
        ->assertOk()->assertJson(['ricevute' => 1, 'messaggi' => 1]);
    expect(RegistroInvio::orderByDesc('id')->first()->documentabile_id)->toBe($nuova->id);
});

test('una ricevuta in coda da più di un\'ora: la pagina delle ricevute lo conta, la pagina dell\'incasso lo dice sull\'emissione', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();
    $utente = amministratoreRicevute();
    $pagina = fn () => $this->actingAs($utente)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id, 'ricevuta' => 'tutte']))->viewData('page')['props']['in_coda_da_tempo'];

    expect($pagina())->toBe(0);
    $this->travel(61)->minutes();
    expect($pagina())->toBe(1);

    $this->actingAs($utente)->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))
        ->assertInertia(fn ($page) => $page->where('emissioni.0.in_coda_da_tempo', true));
});

test('per email e con «invia di nuovo» la copia porta le cifre dell\'originale, anche se dopo la situazione delle quote è cambiata', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 60.00]], '2026-02-01', 60.00);
    $b = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 40.00]], '2026-03-01', 40.00);
    $utente = amministratoreRicevute();
    $this->actingAs($utente)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$b->id], 'bollo' => 'non_dovuta'])->assertOk();

    app(StornoIncassoRateAction::class)->execute($a->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);
    inviaUnaRicevuta($this, $s, $b, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'non_dovuta'], $utente)->assertOk();

    // L'anteprima del blocco conta le copie: escono con le cifre dell'originale.
    expect($this->actingAs($utente)->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$a->id, $b->id]])->json('copie'))->toBe(1);

    $riga = RegistroInvio::where('canale', 'email')->sole();
    $pdf = app(EmissioneRicevute::class)->pdfDelMessaggio($s->condominio, [['incasso' => $b->fresh(), 'dati' => $riga->dati, 'emesso_il' => $riga->emesso_il]], 'Anna Bruni');
    expect(cifreDellaQuota($pdf['contenuti'][0]['quote'][0]))->toBe([15000, 6000, 4000, 5000]);

    $riga->update(['stato' => 'non_partita', 'errore' => 'Casella piena']);
    $this->actingAs($utente)->postJson(route('admin.gestionale.ricevute.invia-di-nuovo', ['condominio' => $s->condominio->id, 'invio' => $riga->id]))->assertOk();
    $nuova = RegistroInvio::orderByDesc('id')->first();
    expect(cifreDellaQuota($nuova->dati['contenuto']['quote'][0]))->toBe([15000, 6000, 4000, 5000])
        ->and($nuova->dati['copia_del'])->not->toBeNull();
});

test('«Riprova le non partite» dopo che l\'indirizzo è stato corretto rimanda una volta sola, al nuovo indirizzo, e l\'avviso si spegne', function () {
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $utente = amministratoreRicevute();
    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'], $utente)->assertOk();
    // Il server aveva rifiutato l'indirizzo sbagliato; l'amministratore lo corregge nell'anagrafica.
    RegistroInvio::query()->update(['stato' => 'non_partita', 'errore' => 'Mailbox unavailable']);
    $s->anna->update(['email' => 'anna.giusta@prova.it']);
    $riprova = fn () => $this->actingAs($utente)->postJson(route('admin.gestionale.ricevute.riprova', ['condominio' => $s->condominio->id]))->assertOk()->json();
    $pagina = fn () => $this->actingAs($utente)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id, 'ricevuta' => 'tutte']))->viewData('page')['props']['non_partite'];

    expect($pagina())->toBe(1)
        ->and($riprova()['inviate'])->toBe(1)
        ->and($riprova()['messaggi'])->toBe(0)
        ->and($riprova()['messaggi'])->toBe(0)
        ->and($pagina())->toBe(0);
    $partiti = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())->all();
    expect($partiti)->toBe(['anna.bruni.' . $s->condominio->id . '@prova.it', 'anna.giusta@prova.it']);
});

test('un invio non partito che non si può più rimandare (l\'incasso annullato, l\'indirizzo tolto) esce dal conteggio delle non partite', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $annullato = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);
    $s->ugo->update(['email' => 'ugo@prova.it']);
    $utente = amministratoreRicevute();
    inviaUnaRicevuta($this, $s, $annullato, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'], $utente)->assertOk();
    inviaUnaRicevuta($this, $s, $ugo, ['destinatari' => [['anagrafica_id' => $s->ugo->id, 'tipi' => ['email']]], 'bollo' => 'non_dovuta'], $utente)->assertOk();
    RegistroInvio::query()->update(['stato' => 'non_partita', 'errore' => 'Connessione rifiutata']);
    $pagina = fn () => $this->actingAs($utente)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id, 'ricevuta' => 'tutte']))->viewData('page')['props']['non_partite'];

    expect($pagina())->toBe(2);
    app(StornoIncassoRateAction::class)->execute($annullato->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);
    $s->ugo->update(['email' => null]);

    expect($pagina())->toBe(0);
    $this->actingAs($utente)->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $s->condominio->id, 'scrittura' => $ugo->id]))
        ->assertInertia(fn ($page) => $page->where('da_riprovare', 0));
});

test('la pagina dell\'incasso offre «Riprova» solo se c\'è un invio da rimandare: uno non partito e poi partito allo stesso indirizzo no', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $utente = amministratoreRicevute();
    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'], $utente)->assertOk();
    $prima = RegistroInvio::sole();
    $prima->update(['stato' => 'non_partita', 'errore' => 'Casella piena']);
    $daRiprovare = fn () => $this->actingAs($utente)->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))
        ->viewData('page')['props']['da_riprovare'];

    expect($daRiprovare())->toBe(1);
    $this->actingAs($utente)->postJson(route('admin.gestionale.ricevute.invia-di-nuovo', ['condominio' => $s->condominio->id, 'invio' => $prima->id]))->assertOk();
    RegistroInvio::orderByDesc('id')->first()->update(['stato' => 'inviata']);
    expect($daRiprovare())->toBe(0);
});

test('«in coda da più di un\'ora» conta le ricevute, non gli indirizzi', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $utente = amministratoreRicevute();
    inviaUnaRicevuta($this, $s, $incasso, ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'], $utente)->assertOk();
    expect(RegistroInvio::count())->toBe(2);

    $this->travel(61)->minutes();
    expect($this->actingAs($utente)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id, 'ricevuta' => 'tutte']))
        ->viewData('page')['props']['in_coda_da_tempo'])->toBe(1);
});

test('con «Escludi le già inviate» l\'anteprima conta quello che partirà davvero, e se le selezionate sono già state inviate tutte l\'invio lo dice', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $gia = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $nuova = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);
    $s->ugo->update(['email' => 'ugo@prova.it']);
    inviaUnaRicevuta($this, $s, $gia, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'], $u)->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    $anteprima = fn (array $ids, int $escludi) => $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]),
        ['incassi' => $ids, 'indirizzi' => ['email'], 'escludi_gia_inviate' => $escludi])->json();

    expect($anteprima([$gia->id, $nuova->id], 1)['email'])->toMatchArray(['persone' => 1, 'ricevute' => 1])
        ->and($anteprima([$gia->id, $nuova->id], 1)['gia_inviate']['ricevute'])->toBe(1)
        ->and($anteprima([$gia->id, $nuova->id], 0)['email'])->toMatchArray(['persone' => 2, 'ricevute' => 2]);

    inviaRicevuteInBlocco($this, $s, ['incassi' => [$gia->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'escludi_gia_inviate' => 1], $u)
        ->assertStatus(422)->assertJson(['message' => 'Le selezionate sono già state inviate tutte: non c\'è niente da mandare.']);
});

test('una ricevuta in coda conta fra le già inviate: un secondo invio in blocco prima che la coda giri non la rimanda', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    inviaRicevuteInBlocco($this, $s, ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'], $u)->assertOk()->assertJson(['in_coda' => 1]);

    $anteprima = $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]),
        ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'escludi_gia_inviate' => 1])->json();
    expect($anteprima['gia_inviate']['ricevute'])->toBe(1)
        ->and($anteprima['email']['ricevute'])->toBe(0);
    inviaRicevuteInBlocco($this, $s, ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'escludi_gia_inviate' => 1], $u)->assertStatus(422);
    expect(RegistroInvio::count())->toBe(1);
});

test('«Mandane una a me» con un server che rifiuta il messaggio risponde con l\'errore del server', function () {
    trasportoCheRifiutaLeRicevute();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    $this->actingAs(amministratoreRicevute())->postJson(route('admin.gestionale.ricevute.prova', ['condominio' => $s->condominio->id]),
        ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'])
        // L'errore intero del server lo legge solo chi gestisce le impostazioni (decisione 204): qui una frase semplice.
        ->assertStatus(422)->assertJsonFragment(['message' => 'La prova non è partita: il server di posta non ha accettato il messaggio.']);
    expect(RegistroInvio::count())->toBe(0);
});

test('chi ha solo un indirizzo di un tipo non scelto (la secondaria, la PEC) va fra quelle da stampare, e l\'anteprima dice quale indirizzo ha', function () {
    $s = scenarioRicevutaIncasso();
    $s->ugo->update(['email_secondaria' => 'ugo.casa@prova.it']);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);

    $a = $this->actingAs(amministratoreRicevute())->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]),
        ['incassi' => [$ugo->id], 'indirizzi' => ['email']])->json();

    expect($a['senza_indirizzo']['persone'])->toBe(['Ugo Neri'])
        ->and($a['altri_indirizzi'])->toBe(['email' => 0, 'email_secondaria' => 1, 'pec' => 0]);
});

test('senza il servizio della coda partono al massimo 50 messaggi per volta: l\'anteprima lo dice, la risposta dice chi resta, e il secondo invio manda il resto', function () {
    Notification::fake();
    config(['queue.default' => 'sync']);
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $ids = incassiDiPiuPersoneConDueIndirizzi($s, 26);
    $campi = ['incassi' => $ids, 'indirizzi' => ['email', 'email_secondaria'], 'bollo_sotto' => 'non_dovuta'];

    $anteprima = $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), $campi)->json();
    expect($anteprima['tetto_messaggi'])->toBe(EmissioneRicevute::TETTO_MESSAGGI_SENZA_CODA)
        ->and($anteprima['email']['messaggi'])->toBe(52);

    $primo = inviaRicevuteInBlocco($this, $s, $campi, $u)->assertOk()->json();
    $ultima = \App\Models\Anagrafica::where('nome', 'Persona 26')->value('id');
    expect($primo['messaggi'])->toBe(50)
        ->and($primo['persone'])->toBe(25)
        ->and($primo['tetto_messaggi'])->toBe(50)
        ->and($primo['restanti'])->toBe(['persone' => [$ultima], 'incassi' => [end($ids)], 'ricevute' => 1, 'messaggi' => 2]);

    $secondo = inviaRicevuteInBlocco($this, $s, ['incassi' => $primo['restanti']['incassi'], 'solo_persone' => $primo['restanti']['persone']] + $campi, $u)->assertOk()->json();
    expect($secondo['messaggi'])->toBe(2)
        ->and($secondo['restanti'])->toBeNull()
        ->and(RegistroInvio::count())->toBe(52);
});

test('con il servizio della coda nessun tetto ai messaggi di un invio in blocco', function () {
    Notification::fake();
    config(['queue.default' => 'database']);
    $s = scenarioRicevutaIncasso();
    $ids = incassiDiPiuPersoneConDueIndirizzi($s, 26);

    $esito = inviaRicevuteInBlocco($this, $s, ['incassi' => $ids, 'indirizzi' => ['email', 'email_secondaria'], 'bollo_sotto' => 'non_dovuta'])->assertOk()->json();
    expect($esito['messaggi'])->toBe(52)
        ->and($esito['restanti'])->toBeNull()
        ->and($esito['tetto_messaggi'])->toBeNull();
});

test('senza il servizio della coda anche «Riprova le non partite» ne rimanda al massimo 50 per volta, e dice quante restano', function () {
    Notification::fake();
    config(['queue.default' => 'sync']);
    $s = scenarioRicevutaIncasso();
    $ids = incassiDiPiuPersoneConDueIndirizzi($s, 26);
    inviaRicevuteInBlocco($this, $s, ['incassi' => $ids, 'indirizzi' => ['email'], 'bollo_sotto' => 'non_dovuta'])->assertOk();
    inviaRicevuteInBlocco($this, $s, ['incassi' => $ids, 'indirizzi' => ['email_secondaria'], 'bollo_sotto' => 'non_dovuta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'non_partita', 'errore' => 'Troppi messaggi']);

    $riprova = fn () => $this->actingAs(amministratoreRicevute())->postJson(route('admin.gestionale.ricevute.riprova', ['condominio' => $s->condominio->id]))->assertOk()->json();
    $primo = $riprova();
    expect($primo['messaggi'])->toBe(50)
        ->and($primo['restanti'])->toBe(['messaggi' => 2]);
    RegistroInvio::where('stato', 'in_coda')->update(['stato' => 'inviata']);
    expect($riprova()['messaggi'])->toBe(2);
});
