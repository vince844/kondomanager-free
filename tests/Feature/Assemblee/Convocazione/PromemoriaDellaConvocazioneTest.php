<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Permission as Permesso;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\PromemoriaDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, la convocazione): il promemoria per email del compito «Spedire la convocazione entro il …», con l'ordinaria di Via Roma 12 (la
 * prima convocazione lunedì 14/12, le raccomandate entro giovedì 03/12).
 *
 * - **A chi**: all'utente del compito, chi ha preparato l'assemblea. Se non c'è più, o non può più gestire le assemblee, a nessuno, e il log lo dice.
 * - **Quando**: due giorni lavorativi prima del giorno limite (martedì 01/12), e la mattina del giorno limite (giovedì 03/12), dalle 8 di Roma; solo se
 *   restano avvisi da spedire. Un giorno che il pianificatore non gira, il comando del giorno dopo manda quello che è dovuto e non è partito.
 * - **Una volta sola per tipo** e per giorno limite, anche se il comando gira due volte; con la data limite spostata il promemoria segue la data nuova.
 * - **Che cosa dice**: l'oggetto, il condominio, quanti avvisi restano e per quale mezzo, il giorno limite in parole, il pulsante alla convocazione; la riga
 *   del registro degli invii con il suo esito («inviata» quando il server accetta il messaggio).
 * - **L'interruttore** spento, o il programma senza un server di posta: nessuna email e nessun errore.
 * - Mai per un'assemblea annullata, registrata a mano o passata.
 *
 * Le catene: le date → il compito → l'email due giorni prima → l'email del giorno → la spedizione di tutti → nessuna email dopo; la data delle raccomandate
 * scelta → il compito spostato → il promemoria sulla data nuova; l'interruttore spento → nessuna email.
 *
 * Che cosa non copre: la pagina delle impostazioni a video (vitest); la scheda della convocazione (`ComeContoSullaDataSceltaTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Le righe del promemoria nel registro degli invii, dalla prima. */
function pdcRighe(int $assemblea): \Illuminate\Support\Collection
{
    return RegistroInvio::query()->where('tipo', 'promemoria_convocazione')->where('documentabile_id', $assemblea)->orderBy('id')->get();
}

/** Le email del promemoria partite (il trasporto delle prove le ricorda). @return list<\Symfony\Component\Mime\Email> */
function pdcEmail(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage())
        ->filter(fn ($m) => str_starts_with((string) $m->getSubject(), 'Convocazione da spedire'))->values()->all();
}

/** Il comando, alle `$quando` di Roma. */
function pdcGira($test, string $quando): void
{
    ConvocazioneDiViaRoma12::oggi($quando);
    $test->artisan('assemblee:promemoria-convocazioni')->assertSuccessful();
}

/** Il compito «Spedire la convocazione» aperto dell'assemblea, o null. */
function pdcCompito(int $assemblea): ?Evento
{
    return Evento::query()->where('meta->type', 'spedire_convocazione')->where('meta->context->assemblea_id', $assemblea)->where('is_completed', false)->first();
}

it('le date → il compito entro il 03/12 → l\'email martedì 01/12 → l\'email giovedì 03/12 → la spedizione di tutti → nessuna email dopo', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $s->persone['bianchi']->forceFill(['indirizzo' => 'Via Roma 12, 20121 Milano'])->saveQuietly();
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    expect(pdcCompito($a->id)?->title)->toBe('Spedire la convocazione entro il 03/12')
        ->and(app(PromemoriaDellaConvocazione::class)->giorni(CarbonImmutable::parse('2026-12-03')))
        ->toEqual(['due_giorni_prima' => CarbonImmutable::parse('2026-12-01'), 'il_giorno' => CarbonImmutable::parse('2026-12-03')]);

    // Lunedì 30/11 non è ancora il giorno; martedì 01/12 prima delle 8 nemmeno.
    pdcGira($this, '2026-11-30 09:00');
    pdcGira($this, '2026-12-01 07:30');
    expect(pdcRighe($a->id))->toHaveCount(0)->and(pdcEmail())->toHaveCount(0);

    pdcGira($this, '2026-12-01 08:15');
    $riga = pdcRighe($a->id)->sole();
    $email = pdcEmail()[0];
    expect($riga->dati['promemoria'])->toBe('due_giorni_prima')
        ->and($riga->dati['limite'])->toBe('2026-12-03')
        ->and($riga->stato)->toBe(RegistroInvio::STATO_INVIATA)
        ->and($riga->mail_log_id)->not->toBeNull()
        ->and($riga->destinatario)->toBe($s->admin->email)
        ->and($riga->canale)->toBe(RegistroInvio::CANALE_EMAIL)
        ->and($riga->anagrafica_id)->toBeNull()
        ->and($email->getTo()[0]->getAddress())->toBe($s->admin->email)
        ->and($email->getSubject())->toBe('Convocazione da spedire entro il 03/12: assemblea ordinaria del 15/12/2026');
    $corpo = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $email->getHtmlBody()), ENT_QUOTES));
    expect($corpo)->toContain('Per l\'assemblea ordinaria del 15/12/2026 del Condominio Via Roma 12 restano 9 avvisi da spedire: 4 per raccomandata, 3 per PEC e 2 da consegnare a mano.')
        ->toContain('Il giorno limite è giovedì 03/12.')
        ->toContain('Apri la convocazione')
        ->toContain('Questo promemoria si spegne nelle impostazioni della posta.')
        ->and((string) $email->getHtmlBody())->toContain(route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'convocazione']));

    // Il comando gira di nuovo lo stesso giorno: niente di nuovo.
    pdcGira($this, '2026-12-01 10:00');
    pdcGira($this, '2026-12-02 08:15');
    expect(pdcRighe($a->id))->toHaveCount(1)->and(pdcEmail())->toHaveCount(1);

    pdcGira($this, '2026-12-03 08:15');
    $giorno = pdcRighe($a->id)->last();
    expect(pdcRighe($a->id))->toHaveCount(2)
        ->and($giorno->dati['promemoria'])->toBe('il_giorno')
        ->and($giorno->dati['limite'])->toBe('2026-12-03')
        ->and(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) pdcEmail()[1]->getHtmlBody()), ENT_QUOTES)))
        ->toContain('Il giorno limite è oggi, giovedì 03/12.');

    // Tutti spediti: il compito si chiude, e il comando non manda più niente.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => collect(['marco', 'carla', 'gallo', 'bianchi'])
        ->map(fn ($x) => (int) ConvocazioneDiViaRoma12::invio($a, $s, $x)->id)->all(), 'canale' => 'raccomandata', 'data' => '2026-12-03'])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => collect(['anna', 'luca'])
        ->map(fn ($x) => (int) ConvocazioneDiViaRoma12::invio($a, $s, $x)->id)->all(), 'canale' => 'a_mano', 'data' => '2026-12-03'])->assertSessionHasNoErrors();
    expect(pdcCompito($a->id))->toBeNull();

    pdcGira($this, '2026-12-04 08:15');
    pdcGira($this, '2026-12-07 08:15');
    expect(pdcRighe($a->id))->toHaveCount(2)->and(pdcEmail())->toHaveCount(2);
});

it('il giorno che il pianificatore non gira: il comando del giorno dopo manda il promemoria dovuto; passato il giorno di legge, il giorno limite è oggi e il promemoria dice che è fuori dai cinque giorni (decisione 665)', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    // Martedì 01/12 il pianificatore non gira: mercoledì 02/12 parte il promemoria dei due giorni.
    pdcGira($this, '2026-12-02 08:15');
    expect(pdcRighe($a->id)->pluck('dati.promemoria')->all())->toBe(['due_giorni_prima']);

    // Giovedì 03/12 neppure: venerdì 04/12 il giorno di legge delle raccomandate (03/12) è passato, e da oggi non c'è più il tempo dei cinque giorni. Come
    // il campo «Spedisco le raccomandate entro», il compito dice oggi (decisione 665): parte quello del giorno, che lo dice fuori dai cinque giorni.
    pdcGira($this, '2026-12-04 08:15');
    expect(pdcRighe($a->id)->pluck('dati.promemoria')->all())->toBe(['due_giorni_prima', 'il_giorno'])
        ->and(pdcRighe($a->id)->last()->dati['limite'])->toBe('2026-12-04')
        ->and(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) pdcEmail()[1]->getHtmlBody()), ENT_QUOTES)))
        ->toContain('Il giorno limite è oggi, venerdì 04/12, fuori dai cinque giorni dell\'art. 66: spedisci appena puoi.');
});

it('il giorno come si legge, a Roma: alle 8:15 di Roma (le 7:15 del server) il promemoria parte; a mezzanotte e mezza di Roma vale il giorno prima', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    // Le 00:30 di Roma di martedì 01/12 sono ancora lunedì 30/11 sul server: prima delle 8, niente.
    pdcGira($this, '2026-12-01 00:30');
    expect(pdcRighe($a->id))->toHaveCount(0);
    // Le 8:15 di Roma sono le 7:15 sul server: per Roma sono passate le 8.
    pdcGira($this, '2026-12-01 08:15');
    expect(pdcRighe($a->id)->pluck('dati.promemoria')->all())->toBe(['due_giorni_prima']);
    // Le 00:30 di Roma di giovedì 03/12 (mercoledì sul server): il giorno limite non è ancora cominciato per il promemoria.
    pdcGira($this, '2026-12-03 00:30');
    expect(pdcRighe($a->id))->toHaveCount(1);
});

it('la data delle raccomandate scelta → il compito spostato → il promemoria sulla data nuova; un giorno già mandato non si rimanda', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $rotta = route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]);

    // Martedì 01/12: il compito si sposta, e i due giorni lavorativi prima sono venerdì 27/11.
    $this->actingAs($s->admin)->patch($rotta, ['giorno' => '2026-12-01'])->assertSessionHasNoErrors();
    expect(pdcCompito($a->id)?->title)->toBe('Spedire la convocazione entro l\'01/12');
    pdcGira($this, '2026-11-27 08:15');
    expect(pdcRighe($a->id)->map(fn ($r) => $r->dati['promemoria'] . ' ' . $r->dati['limite'])->all())->toBe(['due_giorni_prima 2026-12-01']);

    // La data si sposta a mercoledì 02/12: il promemoria dei due giorni è lunedì 30/11, non sabato 28/11.
    $this->actingAs($s->admin)->patch($rotta, ['giorno' => '2026-12-02'])->assertSessionHasNoErrors();
    expect(pdcCompito($a->id)?->title)->toBe('Spedire la convocazione entro il 02/12');
    pdcGira($this, '2026-11-28 08:15');
    expect(pdcRighe($a->id))->toHaveCount(1);
    pdcGira($this, '2026-11-30 08:15');
    expect(pdcRighe($a->id)->map(fn ($r) => $r->dati['promemoria'] . ' ' . $r->dati['limite'])->all())
        ->toBe(['due_giorni_prima 2026-12-01', 'due_giorni_prima 2026-12-02']);

    // Torna al 01/12: quel promemoria è già partito, non si rimanda; quello del giorno parte il 01/12.
    $this->actingAs($s->admin)->patch($rotta, ['giorno' => '2026-12-01'])->assertSessionHasNoErrors();
    pdcGira($this, '2026-11-30 10:00');
    expect(pdcRighe($a->id))->toHaveCount(2);
    pdcGira($this, '2026-12-01 08:15');
    expect(pdcRighe($a->id)->last()->dati)->toMatchArray(['promemoria' => 'il_giorno', 'limite' => '2026-12-01']);
});

it('l\'interruttore spento → nessuna email; riacceso, parte quello che è dovuto', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $impostazioni = app(MailSettings::class);
    expect($impostazioni->promemoria_convocazioni)->toBeTrue();
    $impostazioni->promemoria_convocazioni = false;
    $impostazioni->save();

    pdcGira($this, '2026-12-01 08:15');
    expect(pdcRighe($a->id))->toHaveCount(0)->and(pdcEmail())->toHaveCount(0);

    $impostazioni->promemoria_convocazioni = true;
    $impostazioni->save();
    pdcGira($this, '2026-12-01 09:00');
    expect(pdcRighe($a->id))->toHaveCount(1);
});

it('senza un server di posta: nessuna email, nessuna riga, nessun errore', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    Config::set('mail.default', 'log');

    pdcGira($this, '2026-12-01 08:15');
    expect(pdcRighe($a->id))->toHaveCount(0);
});

it('a nessuno se chi ha preparato l\'assemblea è sospeso, non può più gestire le assemblee o non c\'è più: il log lo dice', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    Log::spy();

    $s->admin->forceFill(['suspended_at' => now()])->saveQuietly();
    pdcGira($this, '2026-12-01 08:15');
    expect(pdcRighe($a->id))->toHaveCount(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains((string) $m, 'chi ha preparato l\'assemblea è sospeso'))->once();
    $s->admin->forceFill(['suspended_at' => null])->saveQuietly();

    $s->admin->revokePermissionTo(Permesso::MANAGE_ASSEMBLEE->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    pdcGira($this, '2026-12-01 08:15');
    expect(pdcRighe($a->id))->toHaveCount(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains((string) $m, 'chi ha preparato l\'assemblea non può più gestire le assemblee'))->once();

    $s->admin->delete();
    pdcGira($this, '2026-12-01 09:00');
    expect(pdcRighe($a->id))->toHaveCount(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains((string) $m, 'chi ha preparato l\'assemblea non c\'è più'))->once();
});

it('il promemoria va a chi ha preparato l\'assemblea, anche se il compito è rinato per chi l\'ha toccata per ultimo', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $altro = \App\Models\User::factory()->create(['email' => 'collaboratore@example.org']);
    pdcCompito($a->id)->forceFill(['created_by' => $altro->id])->saveQuietly();
    pdcGira($this, '2026-12-01 08:15');
    expect(pdcRighe($a->id)->pluck('destinatario')->all())->toBe([$s->admin->email]);
});

it('mai per un\'assemblea annullata, registrata a mano o passata', function (string $caso) {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    expect(pdcCompito($a->id))->not->toBeNull();
    $quando = '2026-12-03 08:15';
    match ($caso) {
        'annullata' => $a->forceFill(['stato' => StatoAssemblea::ANNULLATA])->saveQuietly(),
        'registrata a mano' => $a->forceFill(['stato' => StatoAssemblea::REGISTRATA_A_MANO])->saveQuietly(),
        'passata' => $quando = '2026-12-14 08:15',
    };

    pdcGira($this, $quando);
    expect(pdcRighe($a->id))->toHaveCount(0)->and(pdcEmail())->toHaveCount(0);
})->with(['annullata', 'registrata a mano', 'passata']);

it('il pianificatore lo fa girare ogni mattina alle 8:15 di Roma, e il testo del pianificatore lo dice', function (string $lingua, string $parola) {
    $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'promemoria-convocazioni');

    expect($evento)->not->toBeNull('Manca l\'evento promemoria-convocazioni in routes/console.php')
        ->and($evento->expression)->toBe('15 8 * * *')
        ->and($evento->timezone)->toBe('Europe/Rome')
        ->and(trans('impostazioni.dialogs.cron_guide_1_desc', [], $lingua))->toMatch($parola);

    // L'evento manda davvero: con un'assemblea da spedire il giorno dovuto, la riga nasce.
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    ConvocazioneDiViaRoma12::oggi('2026-12-01 08:15');
    $evento->run(app());
    expect(pdcRighe($a->id))->toHaveCount(1);
})->with([
    'it' => ['it', '/promemoria delle convocazioni da spedire/iu'],
    'en' => ['en', '/reminder/i'],
    'es' => ['es', '/recordatorio/iu'],
    'pt' => ['pt', '/lembrete/iu'],
]);
