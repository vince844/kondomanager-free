<?php

use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * «Prepara l'email» (decisione 207.1), dalla parte del server: la finestra della ricevuta riceve l'oggetto e il testo del messaggio, gli stessi
 * che il programma scrive quando la manda lui, e il PDF si chiede alla rotta della stampa (una richiesta della pagina, non un modulo): è
 * un'emissione del registro di canale `stampa`, con le scelte della finestra.
 *
 * Che cosa NON copre: la condivisione del browser e l'email che si apre (nei test JavaScript).
 */
function preparaOpzioni($test, object $s, int $incasso, $utente): array
{
    return $test->actingAs($utente)->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso]))->assertOk()->json();
}

test('l\'oggetto, il saluto, le righe e la chiusura della bozza sono quelli del messaggio che il programma manda', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['versato_da_id' => $s->carlo->id]);

    $bozza = preparaOpzioni($this, $s, $incasso->id, $u)['bozza_email'];
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();
    $messaggio = notificheRicevutaA($s->anna->email)->sole()->toMail(new AnonymousNotifiable);

    expect($bozza['oggetto'])->toBe($messaggio->subject)
        ->and($bozza['oggetto'])->toBe('Condominio Via dei Pini — Ricevuta n. ' . $incasso->numero_protocollo . ' del 01/02/2026')
        ->and($bozza['saluti'][(string) $s->anna->id])->toBe($messaggio->greeting)
        ->and($bozza['saluti'][(string) $s->carlo->id])->toBe('Gentile Carlo Bruni,')
        ->and($bozza['righe'])->toBe($messaggio->introLines)
        ->and($bozza['chiusura'])->toBe($messaggio->salutation);
});

test('senza server di posta la finestra ha la bozza e gli indirizzi della persona; il PDF chiesto dalla pagina è una stampa nel registro, con il bollo e la causale', function () {
    config(['mail.default' => 'log']);
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    $o = preparaOpzioni($this, $s, $incasso->id, $u);
    $risposta = $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]),
        ['incassi' => [$incasso->id], 'bollo' => 'assolta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'aut. 12', 'stampa_causale' => 1]);

    expect($o['senza_server_posta'])->toBeTrue()
        ->and($o['inviabile'])->toBeTrue()
        ->and($o['bozza_email']['oggetto'])->toContain('Ricevuta n. ' . $incasso->numero_protocollo)
        ->and(collect($o['destinatari'][0]['indirizzi'])->pluck('indirizzo')->all())->toBe([$s->anna->email, $s->anna->email_secondaria]);
    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($risposta->headers->get('Content-Disposition'))->toContain('filename="ricevuta-');
    $riga = RegistroInvio::sole();
    expect($riga->canale)->toBe('stampa')
        ->and($riga->stato)->toBe('stampata')
        ->and($riga->dati)->toMatchArray(['bollo' => 'assolta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'aut. 12', 'stampa_causale' => true])
        ->and($riga->impronta)->toBe(hash('sha256', $risposta->getContent()));
});

/** Quello che la vista del PDF riceve ad ogni PDF fatto da qui in avanti: le ricevute con le loro scelte, come le legge il modello. */
function pdfDellaRicevutaViste(): ArrayObject
{
    $viste = new ArrayObject;
    View::composer('pdf.gestionale.ricevute_incasso', function ($vista) use ($viste) {
        $viste[] = $vista->getData();
    });

    return $viste;
}

test('il PDF di «Prepara l\'email» non ha l\'indirizzo per la busta, come quello che il programma allega; la stampa per la posta lo tiene', function () {
    config(['mail.default' => 'log']);
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $s->anna->update(['indirizzo' => 'Via Garibaldi 5, 10122 Torino']);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50, ['versato_da_id' => $s->carlo->id]);
    $viste = pdfDellaRicevutaViste();
    $rotta = route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]);

    // La finestra chiede il PDF per l'email.
    $this->actingAs($u)->post($rotta, ['incassi' => [$incasso->id], 'per_email' => 1, 'bollo' => 'non_dovuta'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    // Il PDF che il programma allega alla sua email, dalla riga del registro.
    $riga = RegistroInvio::sole();
    app(EmissioneRicevute::class)->pdfDelMessaggio($s->condominio, [['incasso' => $incasso->fresh(), 'dati' => $riga->dati, 'emesso_il' => $riga->emesso_il]], 'Anna Bruni');
    // La stampa per la posta, senza `per_email`.
    $this->actingAs($u)->post($rotta, ['incassi' => [$incasso->id], 'bollo' => 'non_dovuta'])->assertOk();

    expect($viste)->toHaveCount(3);
    [$perEmail, $delMessaggio, $perLaPosta] = array_map(fn ($v) => $v['ricevute'][0], $viste->getArrayCopy());
    $html = fn (array $vista) => View::make('pdf.gestionale.ricevute_incasso', $vista)->render();
    expect($perEmail['indirizzo_per_posta'])->toBeFalse()
        ->and($delMessaggio['indirizzo_per_posta'])->toBeFalse()
        ->and($perLaPosta['indirizzo_per_posta'])->toBeTrue()
        ->and($html($viste[0]))->not->toContain('Via Garibaldi 5')
        ->and($html($viste[1]))->not->toContain('Via Garibaldi 5')
        ->and($html($viste[2]))->toContain('Via Garibaldi 5');
    // Il registro resta lo stesso: due stampe, la prima originale e la seconda copia.
    expect(RegistroInvio::where('canale', 'stampa')->orderBy('id')->get()->map(fn ($r) => isset($r->dati['copia_del']))->all())->toBe([false, true]);
});

test('con più ricevute chieste per email nessuna ha l\'indirizzo per la busta', function () {
    $s = scenarioRicevutaIncasso();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $b = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-05', 60.00);
    $viste = pdfDellaRicevutaViste();

    $this->actingAs(amministratoreRicevute())->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]),
        ['incassi' => [$a->id, $b->id], 'per_email' => '1', 'bollo' => 'non_dovuta'])->assertOk();

    expect($viste)->toHaveCount(1)
        ->and(array_column($viste[0]['ricevute'], 'indirizzo_per_posta'))->toBe([false, false]);
});

/** L'invio di una ricevuta ad Anna, con un server di posta che lo rifiuta: la riga resta `non_partita`. */
function nonPartitaDiAnna($test, object $s, $incasso, $utente): void
{
    $test->actingAs($utente)->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();
    expect(RegistroInvio::sole()->stato)->toBe('non_partita');
}

test('dopo «Prepara l\'email» la riga «non partita» non è più da riprovare: l\'avviso si spegne e «Riprova le non partite» non la manda di nuovo', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $b = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-05', 60.00);
    trasportoCheRifiutaLeRicevute();
    nonPartitaDiAnna($this, $s, $a, $u);
    $avviso = fn () => $this->actingAs($u)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id, 'ricevuta' => 'tutte']))->viewData('page')['props']['non_partite'];
    $suPagina = fn ($incasso) => $this->actingAs($u)->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->viewData('page')['props']['da_riprovare'];
    expect($avviso())->toBe(1)->and($suPagina($a))->toBe(1);

    // L'altro incasso esce con una stampa: la non partita di questo resta.
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$b->id], 'per_email' => 1, 'bollo' => 'assolta'])->assertOk();
    expect($avviso())->toBe(1);

    // «Prepara l'email» per questa: la ricevuta è uscita, e la riga non partita non si riprova.
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$a->id], 'per_email' => 1, 'bollo' => 'assolta'])->assertOk();
    expect($avviso())->toBe(0)->and($suPagina($a))->toBe(0)
        ->and(app(EmissioneRicevute::class)->daRiprovare($s->condominio))->toHaveCount(0);
    $righeEmail = RegistroInvio::where('canale', 'email')->count();
    $riprova = $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.riprova', ['condominio' => $s->condominio->id]))->assertOk()->json();
    expect($riprova['messaggi'])->toBe(0)->and(RegistroInvio::where('canale', 'email')->count())->toBe($righeEmail);
});

test('una stampa uscita prima dell\'invio non partito non lo toglie dalle non partite: conta quella che viene dopo', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$a->id], 'bollo' => 'assolta'])->assertOk();
    trasportoCheRifiutaLeRicevute();
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $a->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();

    expect(RegistroInvio::where('canale', 'email')->sole()->stato)->toBe('non_partita')
        ->and(app(EmissioneRicevute::class)->daRiprovare($s->condominio))->toHaveCount(1);
});

test('lo stesso con l\'archivio «Scarica un PDF per persona»: la ricevuta è uscita, la non partita non si riprova; lo .zip, letto, non resta nella cartella temporanea', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    trasportoCheRifiutaLeRicevute();
    nonPartitaDiAnna($this, $s, $a, $u);
    expect(app(EmissioneRicevute::class)->daRiprovare($s->condominio))->toHaveCount(1);
    $archivi = fn () => glob(sys_get_temp_dir().'/km_archivio_*') ?: [];
    $prima = $archivi();

    // La risposta si legge, come la legge il browser: chi la manda toglie lo .zip dopo l'invio.
    $zip = $this->actingAs($u)->post(route('admin.gestionale.ricevute.pdf-per-persona', ['condominio' => $s->condominio->id]), ['incassi' => [$a->id], 'bollo' => 'assolta'])
        ->assertOk()->streamedContent();

    expect(app(EmissioneRicevute::class)->daRiprovare($s->condominio))->toHaveCount(0)
        ->and(str_starts_with($zip, "PK"))->toBeTrue()
        ->and(array_values(array_diff($archivi(), $prima)))->toBe([]);
});

/**
 * Una ricevuta che va a due persone: l'incasso di Anna versato da Carlo, mandato a tutti e due con un server di posta che rifiuta. Due righe non
 * partite, una per persona. Un PDF della ricevuta porta sempre la persona della posizione (Anna): spegne la sua riga, non quella di Carlo.
 */
function nonPartiteDiAnnaECarlo($test, object $s, $utente): ScritturaContabile
{
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50, ['versato_da_id' => $s->carlo->id]);
    trasportoCheRifiutaLeRicevute();
    $test->actingAs($utente)->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]), [
        'destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']], ['anagrafica_id' => $s->carlo->id, 'tipi' => ['email']]],
        'bollo' => 'non_dovuta',
    ])->assertOk();
    expect(RegistroInvio::where('canale', 'email')->pluck('stato')->all())->toBe(['non_partita', 'non_partita']);

    return $incasso;
}

/** Chi resta da riprovare, per nome. */
function personeDaRiprovare(object $s): array
{
    return app(EmissioneRicevute::class)->daRiprovare($s->condominio)->map(fn (RegistroInvio $r) => $r->anagrafica?->nome)->sort()->values()->all();
}

test('«Prepara l\'email» per Anna spegne la sua riga non partita e non quella di Carlo, che ha versato e non l\'ha avuta: l\'avviso e la pagina dell\'incasso lo contano', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = nonPartiteDiAnnaECarlo($this, $s, $u);
    expect(personeDaRiprovare($s))->toBe(['Anna Bruni', 'Carlo Bruni']);

    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'per_email' => 1, 'bollo' => 'non_dovuta'])->assertOk();

    $avviso = $this->actingAs($u)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id, 'ricevuta' => 'tutte']))->viewData('page')['props']['non_partite'];
    $suPagina = $this->actingAs($u)->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->viewData('page')['props']['da_riprovare'];
    expect(personeDaRiprovare($s))->toBe(['Carlo Bruni'])->and($avviso)->toBe(1)->and($suPagina)->toBe(1);
});

test('la stampa per la posta, che porta l\'indirizzo di Anna, spegne la riga non partita di Anna e lascia quella di Carlo', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = nonPartiteDiAnnaECarlo($this, $s, $u);

    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'bollo' => 'non_dovuta'])->assertOk();

    expect(personeDaRiprovare($s))->toBe(['Carlo Bruni']);
});

test('l\'invio in blocco «anche a chi ha versato» non partito, poi l\'archivio: l\'elenco degli indirizzi ha Anna e non Carlo, e Carlo resta da riprovare', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50, ['versato_da_id' => $s->carlo->id]);
    trasportoCheRifiutaLeRicevute();
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'anche_chi_ha_versato' => 1,
        'indirizzi' => ['email'], 'bollo_sopra' => 'non_dovuta', 'bollo_sotto' => 'non_dovuta'])->assertOk();
    expect(personeDaRiprovare($s))->toBe(['Anna Bruni', 'Carlo Bruni']);

    $zip = $this->actingAs($u)->post(route('admin.gestionale.ricevute.pdf-per-persona', ['condominio' => $s->condominio->id]),
        ['incassi' => [$incasso->id], 'bollo_sopra' => 'non_dovuta', 'bollo_sotto' => 'non_dovuta'])->assertOk()->streamedContent();
    $percorso = tempnam(sys_get_temp_dir(), 'ricevute-zip');
    file_put_contents($percorso, $zip);
    $archivio = new ZipArchive;
    $archivio->open($percorso);
    $indirizzi = (string) $archivio->getFromName(EmissioneRicevute::FILE_DEGLI_INDIRIZZI);
    $archivio->close();
    unlink($percorso);

    expect($indirizzi)->toContain('anna.bruni.')->not->toContain('carlo.bruni.')
        ->and(personeDaRiprovare($s))->toBe(['Carlo Bruni']);
});

test('il PDF di un\'altra ricevuta della stessa persona non spegne nessuna delle due righe non partite', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    nonPartiteDiAnnaECarlo($this, $s, $u);
    $altra = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$altra->id], 'per_email' => 1, 'bollo' => 'non_dovuta'])->assertOk();

    expect(personeDaRiprovare($s))->toBe(['Anna Bruni', 'Carlo Bruni']);
});
