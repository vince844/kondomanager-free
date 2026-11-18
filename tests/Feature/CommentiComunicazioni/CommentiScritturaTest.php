<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti alle comunicazioni: chi scrive, e in quale discussione (Coda 559, decisioni 261.1, 268.1 e 268.3, cambiate dalla 458)
 *
 * Si scrive solo in una discussione che si vede: la discussione unica di una comunicazione ai palazzi, se si ha un'anagrafica
 * in uno dei palazzi destinatari (il commento porta il primo per nome); la propria discussione con lo studio. Lo studio scrive
 * in tutte. Una chiave di una discussione che non si vede è un rifiuto, e nessun commento nasce; una chiave malformata (anche
 * quella di prima di un palazzo, `palazzo:<id>`) è un errore del modulo. «Consenti commenti» spento chiude la discussione a
 * tutti, studio compreso; una comunicazione non ancora uscita non si commenta.
 */

use App\Models\Condominio;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->palazzi = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
    $this->persone = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['subject' => 'Il suo sollecito'], [$this->mario, $this->lucia]);
});

test('il condòmino scrive nella discussione unica: il commento ha il suo palazzo e nessuna persona', function () {
    ccmScrivi($this, $this->mario, $this->palazzi, ccmChiaveUnica(), 'Grazie, ci sarò')->assertSessionHasNoErrors();

    $commenti = ccmCommenti($this->palazzi);
    expect($commenti)->toHaveCount(1)
        ->and($commenti->first()->condominio_id)->toBe($this->aurora->id)
        ->and($commenti->first()->discussione_user_id)->toBeNull()
        ->and($commenti->first()->user_id)->toBe($this->mario->id)
        ->and($commenti->first()->stato)->toBe('pubblicato');
});

test('il condòmino di un palazzo che non è fra i destinatari non scrive; quello dell\'altro palazzo destinatario sì', function () {
    $cirro = Condominio::factory()->create(['nome' => 'Condominio Cirro']);
    $vicino = ccmUtente([$cirro->id], 'Gino Cirro');
    $risposta = ccmScrivi($this, $vicino, $this->palazzi, ccmChiaveUnica(), 'Dal palazzo accanto');

    expect(ccmRifiutato($risposta))->toBeTrue("Risposta {$risposta->status()}: doveva essere un rifiuto")
        ->and(ccmCommenti($this->palazzi))->toHaveCount(0);

    ccmScrivi($this, $this->lucia, $this->palazzi, ccmChiaveUnica(), 'Da Borea')->assertSessionHasNoErrors();
    expect(ccmCommenti($this->palazzi)->sole()->condominio_id)->toBe($this->borea->id);
});

test('chi sta in due palazzi scrive nella discussione unica, con il primo palazzo per nome', function () {
    ccmScrivi($this, $this->paolo, $this->palazzi, ccmChiaveUnica(), 'Scrivo per tutti e due')->assertSessionHasNoErrors();

    expect(ccmCommenti($this->palazzi)->first()->condominio_id)->toBe($this->aurora->id);
});

test('a persone: ognuno scrive nella sua discussione, con il suo palazzo; non in quella di un altro', function () {
    ccmScrivi($this, $this->mario, $this->persone, ccmChiavePersona($this->mario), 'Ho pagato ieri')->assertSessionHasNoErrors();
    $suo = ccmCommenti($this->persone)->first();
    expect($suo->discussione_user_id)->toBe($this->mario->id)
        ->and($suo->condominio_id)->toBe($this->aurora->id);

    $risposta = ccmScrivi($this, $this->mario, $this->persone, ccmChiavePersona($this->lucia), 'Nella discussione di Lucia');
    expect(ccmRifiutato($risposta))->toBeTrue("Risposta {$risposta->status()}: doveva essere un rifiuto")
        ->and(ccmCommenti($this->persone))->toHaveCount(1);
});

test('lo studio scrive in ogni discussione, quella unica e quelle per persona', function () {
    ccmScrivi($this, $this->admin, $this->palazzi, ccmChiaveUnica(), 'Il portone resta chiuso', 'admin')->assertSessionHasNoErrors();
    ccmScrivi($this, $this->admin, $this->persone, ccmChiavePersona($this->lucia), 'Ci mandi la ricevuta', 'admin')->assertSessionHasNoErrors();

    $perPersona = ccmCommenti($this->persone)->first();
    // L'amministratore ha un'anagrafica in Aurora e in Borea: il primo per nome.
    expect(ccmCommenti($this->palazzi)->first()->condominio_id)->toBe($this->aurora->id)
        ->and($perPersona->discussione_user_id)->toBe($this->lucia->id)
        ->and($perPersona->condominio_id)->toBe($this->borea->id)
        ->and($perPersona->stato)->toBe('pubblicato');
});

test('una discussione che la comunicazione non ha è un rifiuto, anche per lo studio', function () {
    $prove = [
        [$this->admin, $this->palazzi, ccmChiavePersona($this->mario), 'admin'],
        [$this->admin, $this->persone, ccmChiaveUnica(), 'admin'],
        [$this->admin, $this->persone, ccmChiavePersona($this->paolo), 'admin'],
        [$this->mario, $this->palazzi, ccmChiavePersona($this->mario), 'user'],
    ];
    $passate = [];
    foreach ($prove as [$user, $comunicazione, $chiave, $porta]) {
        $risposta = ccmScrivi($this, $user, $comunicazione, $chiave, 'Prova', $porta);
        if (! ccmRifiutato($risposta)) {
            $passate[] = "{$user->name} in {$chiave}: {$risposta->status()}";
        }
    }

    expect($passate)->toBe([], "Accettate:\n" . implode("\n", $passate))
        ->and(ccmCommenti($this->palazzi))->toHaveCount(0)
        ->and(ccmCommenti($this->persone))->toHaveCount(0);
});

test('una chiave malformata e un commento vuoto sono errori del modulo', function () {
    // Anche la chiave di prima di un palazzo: dalla decisione 458 le discussioni sono quella unica e quelle per persona.
    foreach (['palazzo', 'palazzo:abc', 'palazzo:' . $this->aurora->id, 'tutti:1', 'stanza:1', ''] as $chiave) {
        $this->actingAs($this->mario)
            ->post(route('user.comunicazioni.commenti.store', $this->palazzi), ['corpo' => 'Prova', 'discussione' => $chiave])
            ->assertSessionHasErrors('discussione');
    }
    $this->actingAs($this->mario)
        ->post(route('user.comunicazioni.commenti.store', $this->palazzi), ['corpo' => '', 'discussione' => ccmChiaveUnica()])
        ->assertSessionHasErrors('corpo');

    expect(ccmCommenti($this->palazzi))->toHaveCount(0);
});

test('con «Consenti commenti» spento nessuno scrive, nemmeno lo studio; i commenti che ci sono restano', function () {
    ccmCommentoSalvato($this->palazzi, $this->mario, 'Scritto prima', $this->aurora);
    $this->palazzi->update(['can_comment' => false]);

    $condomino = ccmScrivi($this, $this->mario, $this->palazzi, ccmChiaveUnica(), 'Dopo, dal portale');
    $studio = ccmScrivi($this, $this->admin, $this->palazzi, ccmChiaveUnica(), 'Dopo, dallo studio', 'admin');

    expect(ccmRifiutato($condomino))->toBeTrue("Il condòmino: {$condomino->status()}")
        ->and(ccmRifiutato($studio))->toBeTrue("Lo studio: {$studio->status()}")
        ->and(ccmCommenti($this->palazzi)->pluck('corpo')->all())->toBe(['Scritto prima']);

    $this->withoutVite();
    $props = ccmScheda($this, $this->mario, $this->palazzi);
    $discussione = ccmDiscussione($props, ccmChiaveUnica());
    expect($discussione['puo_scrivere'])->toBeFalse()
        ->and(collect($discussione['commenti'])->pluck('corpo')->all())->toBe(['Scritto prima']);
});

test('una comunicazione non ancora approvata non si commenta dal portale', function () {
    $this->palazzi->update(['is_approved' => false]);

    $risposta = ccmScrivi($this, $this->mario, $this->palazzi, ccmChiaveUnica(), 'Troppo presto');
    expect(ccmRifiutato($risposta))->toBeTrue("Risposta {$risposta->status()}")
        ->and(ccmCommenti($this->palazzi))->toHaveCount(0);
});

test('il testo del commento si salva senza HTML', function () {
    ccmScrivi($this, $this->mario, $this->palazzi, ccmChiaveUnica(), '<b>Grazie</b> <script>alert(1)</script>')->assertSessionHasNoErrors();

    expect(ccmCommenti($this->palazzi)->first()->corpo)->toBe('Grazie alert(1)');
});
