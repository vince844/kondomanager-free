<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Conferma di lettura delle comunicazioni: «presa visione» (Coda 594, decisione 310, punto 3)
 *
 * Sulle comunicazioni dove l'amministratore la chiede, il destinatario trova nel portale il pulsante «Confermo di
 * aver letto». Le regole:
 * - c'è solo dove è chiesta, e solo per chi è destinatario; chi ha scritto la comunicazione non conferma;
 * - **si registra anche con l'impostazione generale spenta**: è un gesto del condòmino;
 * - premerlo due volte non cambia niente: resta il giorno e l'ora della prima volta;
 * - togliere la spunta dopo nasconde il pulsante e lascia le conferme già date;
 * - la riga del registro è la stessa dell'apertura: una per persona e per comunicazione.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

require_once __DIR__ . '/LettureScenario.php';

beforeEach(function () {
    lctPrepara($this);
});

test('la rotta della conferma c\'è, nel portale, e si chiama con POST', function () {
    expect(Route::has(LCT_ROTTA_PRESA_VISIONE))->toBeTrue('Manca la rotta ' . LCT_ROTTA_PRESA_VISIONE);

    $rotta = Route::getRoutes()->getByName(LCT_ROTTA_PRESA_VISIONE);

    expect($rotta->methods())->toBe(['POST'])
        ->and($rotta->uri())->toEndWith('comunicazioni/{comunicazione}/presa-visione');
});

test('dove non è chiesta il destinatario non ha il pulsante, e la conferma è rifiutata', function () {
    expect(lctLettura($this, $this->mario, $this->c)['puo_confermare'])->toBeFalse();

    lctConferma($this, $this->mario, $this->c)->assertForbidden();

    expect(lctRighe())->toHaveCount(0);
});

test('dove è chiesta il destinatario conferma, anche con l\'impostazione generale spenta', function () {
    $c = lctChiedi($this->c);
    expect(lctLettura($this, $this->mario, $c)['puo_confermare'])->toBeTrue();

    lctConferma($this, $this->mario, $c)->assertRedirect()->assertSessionHasNoErrors();

    $riga = lctRiga($c, $this->mario);
    expect($riga)->not->toBeNull('La presa visione non è stata registrata')
        ->and($riga->presa_visione_il)->toBe(LCT_ADESSO)
        // L'impostazione generale è spenta: l'apertura non si registra, la presa visione sì.
        ->and($riga->aperta_il)->toBeNull()
        ->and(lctRighe())->toHaveCount(1);
});

test('con l\'impostazione accesa la riga è una sola, con tutte e due le date', function () {
    lctImpostazione(true);
    $c = lctChiedi($this->c);
    lctApri($this, $this->mario, $c)->assertOk();

    $this->travelTo(Carbon::parse('2026-11-12 10:05:00', 'UTC'));
    lctConferma($this, $this->mario, $c)->assertSessionHasNoErrors();

    $riga = lctRiga($c, $this->mario);
    expect(lctRighe())->toHaveCount(1)
        ->and($riga->aperta_il)->toBe(LCT_ADESSO)
        ->and($riga->presa_visione_il)->toBe('2026-11-12 10:05:00');
});

test('premerlo due volte non cambia niente: resta la prima conferma', function () {
    $c = lctChiedi($this->c);
    lctConferma($this, $this->mario, $c)->assertSessionHasNoErrors();

    $this->travelTo(Carbon::parse('2026-11-13 18:00:00', 'UTC'));
    lctConferma($this, $this->mario, $c)->assertRedirect()->assertSessionHasNoErrors();

    expect(lctRighe())->toHaveCount(1)
        ->and(lctRiga($c, $this->mario)->presa_visione_il)->toBe(LCT_ADESSO);
});

test('dopo la conferma la scheda dice quando, e il pulsante non c\'è più', function () {
    $c = lctChiedi($this->c);
    lctConferma($this, $this->mario, $c);

    expect(lctLettura($this, $this->mario, $c))->toBe([
        'apertura' => null,
        'puo_confermare' => false,
        'presa_visione' => ['il' => '12/11/2026', 'alle' => '11:00'],
    ]);
});

test('chi non è destinatario non conferma', function (string $caso) {
    $autore = $caso === 'chi l\'ha scritta' ? $this->mario : $this->admin;
    $campi = $caso === 'non ancora uscita' ? ['is_published' => false] : [];
    $c = lctChiedi(ccmComunicazione([$this->aurora], $autore, $campi));
    $chi = match ($caso) {
        'un altro palazzo' => $this->lucia,
        'chi l\'ha scritta' => $this->mario,
        'non ancora uscita' => $this->paolo,
    };

    expect(lctRifiutato(lctConferma($this, $chi, $c)))->toBeTrue("La conferma è passata ({$caso})")
        ->and(lctRighe())->toHaveCount(0);
})->with(['un altro palazzo', 'chi l\'ha scritta', 'non ancora uscita']);

test('una comunicazione indirizzata a delle persone la confermano solo loro', function () {
    $perPaolo = lctChiedi(ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Conguaglio dell\'acqua'], [$this->paolo]));

    expect(lctRifiutato(lctConferma($this, $this->mario, $perPaolo)))->toBeTrue();
    lctConferma($this, $this->paolo, $perPaolo)->assertSessionHasNoErrors();

    expect(lctRighe())->toHaveCount(1)
        ->and(lctRiga($perPaolo, $this->paolo)->presa_visione_il)->toBe(LCT_ADESSO);
});

test('togliere la spunta dopo nasconde il pulsante e lascia le conferme già date', function () {
    $c = lctChiedi($this->c);
    lctConferma($this, $this->mario, $c)->assertSessionHasNoErrors();

    $c = lctChiedi($c, false);

    // Mario ha confermato: la sua conferma resta scritta, e lui la legge ancora.
    expect(lctRiga($c, $this->mario)->presa_visione_il)->toBe(LCT_ADESSO)
        ->and(lctLettura($this, $this->mario, $c))->toBe([
            'apertura' => null,
            'puo_confermare' => false,
            'presa_visione' => ['il' => '12/11/2026', 'alle' => '11:00'],
        ]);

    // Paolo non aveva confermato: non ha più il pulsante, e la conferma è rifiutata.
    expect(lctLettura($this, $this->paolo, $c)['puo_confermare'])->toBeFalse();
    lctConferma($this, $this->paolo, $c)->assertForbidden();
    expect(lctRiga($c, $this->paolo))->toBeNull();
});

test('nel pannello non c\'è una rotta per confermare al posto del condòmino', function () {
    expect(Route::has('admin.comunicazioni.presa-visione'))->toBeFalse();
});
