<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Pubblicazione programmata delle comunicazioni: il modulo del pannello (Coda 589, decisione 290)
 *
 * - **L'ora** arriva come la scrive l'amministratore, nel campo `data_programmata` (`2027-01-15T09:00`). Il server la
 *   legge nel fuso dell'installazione (`config('app.user_timezone')`) e la salva in UTC: il browser non fa conti.
 * - **Programma chi ha «Pubblica comunicazioni»**, solo dal pannello. Nessun permesso nuovo.
 * - **Il server rifiuta** un'ora passata, un'ora insieme a «Pubblicata», chi non ha il permesso, una comunicazione in
 *   attesa di approvazione.
 * - **In modifica** senza il campo non si tocca niente; il campo vuoto toglie la programmazione; `programmata_da` cambia
 *   solo quando cambia l'ora.
 *
 * L'orologio è fermo al 12/11/2026, ore 10:00 UTC: le 11:00 a Roma.
 */

use App\Models\Comunicazione;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/ProgrammateScenario.php';

beforeEach(function () {
    cprPrepara($this);
});

// --- La creazione --------------------------------------------------------------------------------------

test('l\'ora scelta vale nel fuso dell\'installazione, con l\'ora solare e con l\'ora legale', function (string $scritta, string $utc) {
    cprCrea($this, $this->admin, cprModulo([$this->aurora], ['data_programmata' => $scritta]))->assertSessionHasNoErrors();

    $riga = cprRiga(cprUltima());
    expect($riga->programmata_per)->toBe($utc)
        ->and((int) $riga->programmata_da)->toBe($this->admin->id)
        ->and((bool) $riga->is_published)->toBeFalse()
        ->and((bool) $riga->is_approved)->toBeTrue()
        ->and($riga->pubblicata_il)->toBeNull();
})->with([
    'gennaio, ora solare: le 9:00 di Roma sono le 8:00 UTC' => ['2027-01-15T09:00', '2027-01-15 08:00:00'],
    'luglio, ora legale: le 9:00 di Roma sono le 7:00 UTC' => ['2027-07-15T09:00', '2027-07-15 07:00:00'],
    'a cavallo della mezzanotte: le 00:30 di Roma sono le 23:30 UTC del giorno prima' => ['2027-01-15T00:30', '2027-01-14 23:30:00'],
]);

test('con un altro fuso dell\'installazione la stessa ora scritta è un altro momento', function () {
    config(['app.user_timezone' => 'America/New_York']);

    cprCrea($this, $this->admin, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']))->assertSessionHasNoErrors();

    expect(cprRiga(cprUltima())->programmata_per)->toBe('2027-01-15 14:00:00');
});

test('una comunicazione programmata non avvisa nessuno quando la si scrive', function () {
    cprCrea($this, $this->admin, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']))->assertSessionHasNoErrors();

    expect(cprAvvisati(CPR_MAIL_NUOVA))->toBe([])
        ->and(class_exists(CPR_MAIL_CONFERMA) ? ccmDestinatari(CPR_MAIL_CONFERMA) : [])->toBe([]);
});

test('il server rifiuta l\'ora passata, l\'ora scritta male e l\'ora insieme a «Pubblicata»', function (array $campi, string $messaggio) {
    $prima = Comunicazione::count();

    $risposta = cprCrea($this, $this->admin, cprModulo([$this->aurora], $campi));

    $risposta->assertSessionHasErrors('data_programmata');
    expect(Comunicazione::count())->toBe($prima);
    if ($messaggio !== '') {
        expect(session('errors')->first('data_programmata'))->toBe(__($messaggio));
    }
})->with([
    // Le 10:59 di Roma sono le 9:59 UTC: un minuto prima di adesso.
    'un minuto fa' => [['data_programmata' => '2026-11-12T10:59'], 'comunicazioni.scheduling.must_be_future'],
    'adesso esatto' => [['data_programmata' => '2026-11-12T11:00'], 'comunicazioni.scheduling.must_be_future'],
    'ieri' => [['data_programmata' => '2026-11-11T18:00'], 'comunicazioni.scheduling.must_be_future'],
    'insieme a «Pubblicata»' => [['data_programmata' => '2027-01-15T09:00', 'is_published' => true], 'comunicazioni.scheduling.not_with_published'],
    'scritta male' => [['data_programmata' => '15/01/2027 09:00'], ''],
    'solo il giorno' => [['data_programmata' => '2027-01-15'], ''],
]);

test('fra un minuto si può', function () {
    // Le 11:01 di Roma sono le 10:01 UTC.
    cprCrea($this, $this->admin, cprModulo([$this->aurora], ['data_programmata' => '2026-11-12T11:01']))->assertSessionHasNoErrors();

    expect(cprRiga(cprUltima())->programmata_per)->toBe('2026-11-12 10:01:00');
});

test('senza il campo, o con il campo vuoto, la comunicazione nasce com\'è sempre nata', function (array $campi, bool $pubblicata) {
    cprCrea($this, $this->admin, cprModulo([$this->aurora], $campi))->assertSessionHasNoErrors();

    $riga = cprRiga(cprUltima());
    expect($riga->programmata_per)->toBeNull()
        ->and($riga->programmata_da)->toBeNull()
        ->and((bool) $riga->is_published)->toBe($pubblicata)
        ->and($riga->pubblicata_il)->toBe($pubblicata ? CPR_ADESSO : null);
})->with([
    'bozza senza il campo' => [[], false],
    'bozza con il campo vuoto' => [['data_programmata' => null], false],
    'bozza con il campo a stringa vuota' => [['data_programmata' => ''], false],
    'pubblicata senza il campo' => [['is_published' => true], true],
    'pubblicata con il campo vuoto' => [['is_published' => true, 'data_programmata' => null], true],
]);

// --- Chi può programmare -------------------------------------------------------------------------------

test('il collaboratore di serie programma: ha «Pubblica comunicazioni»', function () {
    cprCrea($this, $this->collaboratrice, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']))->assertSessionHasNoErrors();

    $riga = cprRiga(cprUltima());
    expect($riga->programmata_per)->toBe('2027-01-15 08:00:00')
        ->and((int) $riga->programmata_da)->toBe($this->collaboratrice->id);
});

test('chi ha il pannello e crea comunicazioni, ma non le pubblica, non le programma', function () {
    $ruolo = ccmRuolo('segreteria', ['Accesso pannello amministratore', 'Crea comunicazioni', 'Visualizza comunicazioni', 'Modifica comunicazioni']);
    $segreteria = ccmUtente([$this->aurora->id], 'Sara Segreteria', $ruolo);
    $prima = Comunicazione::count();

    $risposta = cprCrea($this, $segreteria, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']));

    $risposta->assertSessionHasErrors('data_programmata');
    expect(session('errors')->first('data_programmata'))->toBe(__('comunicazioni.scheduling.not_allowed'))
        ->and(Comunicazione::count())->toBe($prima);

    // Senza l'ora la sua comunicazione nasce come prima: in attesa di approvazione.
    cprCrea($this, $segreteria, cprModulo([$this->aurora]))->assertSessionHasNoErrors();
    expect((bool) cprRiga(cprUltima())->is_approved)->toBeFalse();
});

test('dal portale non si programma: il campo non conta', function () {
    $ruolo = ccmRuolo('condomino che pubblica', ['Crea comunicazioni', 'Pubblica comunicazioni', 'Visualizza comunicazioni']);
    $condomino = ccmUtente([$this->aurora->id], 'Carlo Condomino', $ruolo);

    $this->actingAs($condomino)->post(route('user.comunicazioni.store'), [
        'subject' => 'Festa in cortile', 'description' => 'Sabato alle 18.', 'priority' => 'bassa',
        'is_featured' => false, 'is_published' => true, 'is_private' => false, 'condomini_ids' => [$this->aurora->id],
        'data_programmata' => '2027-01-15T09:00',
    ])->assertSessionHasNoErrors();

    $riga = cprRiga(cprUltima());
    expect($riga->subject)->toBe('Festa in cortile')
        ->and($riga->programmata_per)->toBeNull()
        ->and($riga->programmata_da)->toBeNull();
});

// --- La modifica ---------------------------------------------------------------------------------------

test('una bozza diventa programmata dalla modifica, e non avvisa nessuno', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);

    cprModifica($this, $this->collaboratrice, $c, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']))->assertSessionHasNoErrors();

    $riga = cprRiga($c);
    expect($riga->programmata_per)->toBe('2027-01-15 08:00:00')
        ->and((int) $riga->programmata_da)->toBe($this->collaboratrice->id)
        ->and((bool) $riga->is_published)->toBeFalse()
        ->and(cprAvvisati(CPR_MAIL_NUOVA))->toBe([]);
});

test('in modifica, senza il campo la programmazione resta; con il campo vuoto si toglie', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');
    $modulo = cprModulo([$this->aurora], ['subject' => 'Chiusura dell\'acqua, martedì']);

    cprModifica($this, $this->collaboratrice, $c, $modulo)->assertSessionHasNoErrors();
    $riga = cprRiga($c);
    expect($riga->subject)->toBe('Chiusura dell\'acqua, martedì')
        ->and($riga->programmata_per)->toBe('2027-01-15 08:00:00')
        ->and((int) $riga->programmata_da)->toBe($this->admin->id);

    cprModifica($this, $this->collaboratrice, $c, $modulo + ['data_programmata' => null])->assertSessionHasNoErrors();
    $riga = cprRiga($c);
    expect($riga->programmata_per)->toBeNull()
        ->and($riga->programmata_da)->toBeNull()
        ->and((bool) $riga->is_published)->toBeFalse();
});

test('chi programma è chi sceglie l\'ora: rimandare la stessa ora non lo cambia, cambiarla sì', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');

    cprModifica($this, $this->collaboratrice, $c, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']))->assertSessionHasNoErrors();
    expect((int) cprRiga($c)->programmata_da)->toBe($this->admin->id);

    cprModifica($this, $this->collaboratrice, $c, cprModulo([$this->aurora], ['data_programmata' => '2027-02-01T18:30']))->assertSessionHasNoErrors();
    $riga = cprRiga($c);
    expect($riga->programmata_per)->toBe('2027-02-01 17:30:00')
        ->and((int) $riga->programmata_da)->toBe($this->collaboratrice->id);
});

test('«Pubblicata» su una programmata la fa uscire subito, con gli avvisi di sempre e senza la conferma', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');

    cprModifica($this, $this->admin, $c, cprModulo([$this->aurora], ['is_published' => true, 'data_programmata' => null]))->assertSessionHasNoErrors();

    $riga = cprRiga($c);
    expect((bool) $riga->is_published)->toBeTrue()
        ->and($riga->programmata_per)->toBeNull()
        ->and($riga->programmata_da)->toBeNull()
        ->and($riga->pubblicata_il)->toBe(CPR_ADESSO)
        ->and(cprAvvisati(CPR_MAIL_NUOVA))->toBe(ccmIds($this->mario, $this->paolo, $this->collaboratrice))
        ->and(class_exists(CPR_MAIL_CONFERMA) ? ccmDestinatari(CPR_MAIL_CONFERMA) : [])->toBe([]);
});

test('una comunicazione già pubblicata si può riportare a programmata: sparisce fino all\'ora scelta', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin);

    cprModifica($this, $this->admin, $c, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']))->assertSessionHasNoErrors();

    $riga = cprRiga($c);
    expect((bool) $riga->is_published)->toBeFalse()
        ->and($riga->pubblicata_il)->toBeNull()
        ->and($riga->programmata_per)->toBe('2027-01-15 08:00:00')
        ->and($c->fresh()->visibileNelPortaleA($this->mario))->toBeFalse();
});

test('una comunicazione in attesa di approvazione non si programma', function () {
    $c = ccmComunicazione([$this->aurora], $this->mario, ['is_approved' => false, 'is_published' => false]);

    $risposta = cprModifica($this, $this->admin, $c, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']));

    $risposta->assertSessionHasErrors('data_programmata');
    expect(session('errors')->first('data_programmata'))->toBe(__('comunicazioni.scheduling.needs_approval'))
        ->and(cprRiga($c)->programmata_per)->toBeNull();
});

test('in modifica valgono gli stessi rifiuti: l\'ora passata e l\'ora insieme a «Pubblicata»', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');

    cprModifica($this, $this->admin, $c, cprModulo([$this->aurora], ['data_programmata' => '2026-11-12T10:00']))
        ->assertSessionHasErrors('data_programmata');
    cprModifica($this, $this->admin, $c, cprModulo([$this->aurora], ['data_programmata' => '2027-03-01T09:00', 'is_published' => true]))
        ->assertSessionHasErrors('data_programmata');

    $riga = cprRiga($c);
    expect($riga->programmata_per)->toBe('2027-01-15 08:00:00')
        ->and((bool) $riga->is_published)->toBeFalse();
});

test('i quattro rifiuti hanno il loro testo, nelle quattro lingue', function () {
    $chiavi = ['must_be_future', 'not_with_published', 'not_allowed', 'needs_approval'];
    foreach ($chiavi as $chiave) {
        foreach (['it', 'en', 'es', 'pt'] as $lingua) {
            expect(Illuminate\Support\Facades\Lang::hasForLocale("comunicazioni.scheduling.{$chiave}", $lingua))->toBeTrue("Manca comunicazioni.scheduling.{$chiave} in {$lingua}");
        }
    }

    expect(__('comunicazioni.scheduling.must_be_future', [], 'it'))->toBe('La data di pubblicazione deve essere nel futuro.')
        ->and(__('comunicazioni.scheduling.not_with_published', [], 'it'))->toBe('Una comunicazione pubblicata subito non ha una data di pubblicazione programmata.')
        ->and(__('comunicazioni.scheduling.not_allowed', [], 'it'))->toBe('Non hai il permesso di programmare la pubblicazione.')
        ->and(__('comunicazioni.scheduling.needs_approval', [], 'it'))->toBe('Una comunicazione in attesa di approvazione non si programma: prima va approvata.')
        ->and(__('validation.attributes.comunicazioni.data_programmata', [], 'it'))->toBe('data di pubblicazione');
});
