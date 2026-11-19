<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Pubblicazione programmata delle comunicazioni: l'uscita (Coda 589, decisione 290)
 *
 * - **Un comando**, `comunicazioni:pubblica-programmate`, che il pianificatore lancia ogni minuto: fa uscire le
 *   comunicazioni con l'ora passata, e solo quelle.
 * - **Gli avvisi partono all'uscita**, a chi la vede in quel momento: non quando la si scrive, e non a chi nel frattempo è
 *   uscito dal palazzo.
 * - **Due corse insieme non avvisano due volte**: ogni comunicazione si prenota con un aggiornamento condizionato.
 * - **Se il pianificatore era fermo, esce appena riparte** (decisione 290): in ritardo, con l'ora vera dell'uscita.
 *
 * L'orologio è fermo al 12/11/2026, ore 10:00 UTC.
 */

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/ProgrammateScenario.php';

beforeEach(function () {
    cprPrepara($this);
});

test('il comando fa uscire le comunicazioni con l\'ora passata, e solo quelle', function () {
    $scaduta = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:59:00');
    $diAdesso = cprProgrammata([$this->aurora], $this->admin, CPR_ADESSO);
    $futura = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 10:01:00');
    $bozza = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);

    cprPubblica($this);

    foreach ([$scaduta, $diAdesso] as $uscita) {
        $riga = cprRiga($uscita);
        expect((bool) $riga->is_published)->toBeTrue()
            ->and($riga->pubblicata_il)->toBe(CPR_ADESSO)
            ->and($riga->programmata_per)->toBeNull()
            ->and($riga->programmata_da)->toBeNull();
    }
    expect((bool) cprRiga($futura)->is_published)->toBeFalse()
        ->and(cprRiga($futura)->programmata_per)->toBe('2026-11-12 10:01:00')
        ->and((int) cprRiga($futura)->programmata_da)->toBe($this->admin->id)
        ->and((bool) cprRiga($bozza)->is_published)->toBeFalse();
});

test('gli avvisi partono all\'uscita, non prima, a chi la vede in quel momento', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 10:30:00');

    cprPubblica($this);
    expect(cprAvvisati(CPR_MAIL_NUOVA))->toBe([]);

    // Fra la programmazione e l'uscita Lucia entra in Aurora e Mario ne esce.
    $this->lucia->anagrafica->condomini()->attach($this->aurora->id);
    $this->mario->anagrafica->condomini()->detach($this->aurora->id);

    $this->travelTo(Carbon::parse('2026-11-12 10:30:00', 'UTC'));
    cprPubblica($this);

    // L'autore non riceve l'annuncio della propria.
    expect(cprAvvisati(CPR_MAIL_NUOVA))->toBe(ccmIds($this->lucia, $this->paolo, $this->collaboratrice))
        ->and($c->fresh()->visibileNelPortaleA($this->lucia))->toBeTrue();
});

test('una comunicazione a persone avvisa quelle persone', function () {
    $c = cprProgrammata([$this->aurora, $this->borea], $this->admin, '2026-11-12 09:00:00');
    $c->anagrafiche()->attach([$this->lucia->anagrafica->id]);

    cprPubblica($this);

    expect(cprAvvisati(CPR_MAIL_NUOVA))->toBe(ccmIds($this->lucia));
});

test('lanciato due volte, il comando avvisa una volta sola', function () {
    cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:00:00');

    cprPubblica($this);
    cprPubblica($this);

    foreach ([$this->mario, $this->paolo] as $condomino) {
        Notification::assertSentToTimes($condomino->anagrafica, CPR_MAIL_NUOVA, 1);
    }
    expect(class_exists(CPR_MAIL_CONFERMA))->toBeTrue('Manca la notifica di conferma');
    Notification::assertSentToTimes($this->admin, CPR_MAIL_CONFERMA, 1);
});

test('due corse sulla stessa comunicazione: la seconda la trova già presa e non fa niente', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:00:00');
    $regole = CPR_REGOLE;
    expect(class_exists($regole))->toBeTrue('Manca la classe delle regole');

    // Due corse hanno letto la stessa riga: tutte e due la credono da pubblicare.
    $prima = Comunicazione::findOrFail($c->id);
    $seconda = Comunicazione::findOrFail($c->id);

    expect($regole::pubblica($prima))->toBeTrue()
        ->and($regole::pubblica($seconda))->toBeFalse();

    Notification::assertSentToTimes($this->mario->anagrafica, CPR_MAIL_NUOVA, 1);
    Notification::assertSentToTimes($this->admin, CPR_MAIL_CONFERMA, 1);
});

test('`pubblicaScadute()` dice quante ne ha fatte uscire', function () {
    $regole = CPR_REGOLE;
    cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:00:00');
    cprProgrammata([$this->borea], $this->admin, '2026-11-12 09:30:00');
    cprProgrammata([$this->borea], $this->admin, '2026-11-13 09:30:00');

    expect($regole::pubblicaScadute())->toBe(2)
        ->and($regole::pubblicaScadute())->toBe(0);
});

test('se il pianificatore era fermo, la comunicazione esce appena riparte, con l\'ora vera', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2026-11-09 08:00:00');

    cprPubblica($this);

    $riga = cprRiga($c);
    expect((bool) $riga->is_published)->toBeTrue()
        ->and($riga->pubblicata_il)->toBe(CPR_ADESSO)
        ->and(cprAvvisati(CPR_MAIL_NUOVA))->toBe(ccmIds($this->mario, $this->paolo, $this->collaboratrice));
});

test('una comunicazione senza approvazione non esce, nemmeno con l\'ora passata', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:00:00');
    // Uno stato che le regole non lasciano nascere, scritto dal database: il comando non lo deve pubblicare.
    DB::table('comunicazioni')->where('id', $c->id)->update(['is_approved' => false]);

    cprPubblica($this);

    expect((bool) cprRiga($c)->is_published)->toBeFalse()
        ->and(cprAvvisati(CPR_MAIL_NUOVA))->toBe([]);
});

test('una comunicazione che esce da sola è visibile nel portale da quel momento', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 10:30:00');

    expect($c->visibileNelPortaleA($this->mario))->toBeFalse()
        ->and($this->actingAs($this->mario)->get(route('user.comunicazioni.show', $c))->status())->toBeIn([403, 404]);

    $this->travelTo(Carbon::parse('2026-11-12 10:31:00', 'UTC'));
    cprPubblica($this);

    $this->actingAs($this->mario)->get(route('user.comunicazioni.show', $c))->assertOk();
});

// --- Il pianificatore ----------------------------------------------------------------------------------

test('il pianificatore lancia l\'uscita ogni minuto, senza blocco contro le sovrapposizioni', function () {
    $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === CPR_EVENTO);

    expect($evento)->not->toBeNull('Manca l\'evento ' . CPR_EVENTO . ' in routes/console.php')
        ->and($evento->expression)->toBe('* * * * *')
        // Un blocco rimasto appeso dopo un arresto fermerebbe le uscite per un giorno: la doppia corsa la regge la prenotazione.
        ->and($evento->withoutOverlapping)->toBeFalse();
});

test('l\'evento del pianificatore fa uscire davvero le comunicazioni', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:00:00');
    $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === CPR_EVENTO);
    expect($evento)->not->toBeNull();

    $evento->run(app());

    expect((bool) cprRiga($c)->is_published)->toBeTrue();
});

test('il testo che dice che cosa fa il pianificatore nomina le comunicazioni programmate', function (string $lingua, string $parola) {
    $testo = trans('impostazioni.dialogs.cron_guide_1_desc', [], $lingua);

    expect($testo)->not->toBe('impostazioni.dialogs.cron_guide_1_desc')
        ->and($testo)->toMatch($parola);
})->with([
    'it' => ['it', '/comunicazioni programmate/iu'],
    'en' => ['en', '/scheduled/i'],
    'es' => ['es', '/programad/iu'],
    'pt' => ['pt', '/programad|agendad/iu'],
]);
