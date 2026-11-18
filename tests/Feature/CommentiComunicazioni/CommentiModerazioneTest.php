<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti alle comunicazioni: la moderazione, le azioni sui commenti, le impostazioni e l'Inbox (Code 559 e 558)
 *
 * Ogni comunicazione ha «Commenti in moderazione»: acceso, i commenti dei condòmini aspettano; spento, escono subito. Lo
 * studio esce sempre subito. «Forza moderazione commenti» non c'è più (decisione 282.2): chi non ha il permesso di
 * pubblicare aspetta comunque. Approvare, nascondere, modificare ed
 * eliminare i commenti degli altri vogliono il pannello e il permesso insieme: il collaboratore di serie tocca solo i suoi.
 * Un commento in attesa apre una voce nell'Inbox che dice «comunicazione» e porta alla scheda.
 */

use App\Models\Commento;
use App\Models\Evento;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
});

function ccmStatoDopoScrittura($test, $user, string $porta = 'user'): string
{
    ccmScrivi($test, $user, $test->c, ccmChiaveUnica(), 'Prova di ' . $user->name, $porta)->assertSessionHasNoErrors();

    return ccmCommenti($test->c)->last()->stato;
}

test('con la moderazione accesa il condòmino aspetta, lo studio esce subito', function () {
    expect(ccmStatoDopoScrittura($this, $this->mario))->toBe('in_attesa')
        ->and(ccmStatoDopoScrittura($this, $this->admin, 'admin'))->toBe('pubblicato')
        ->and(ccmStatoDopoScrittura($this, $this->collaboratrice, 'admin'))->toBe('pubblicato');
});

test('con la moderazione spenta il condòmino esce subito; chi non ha il permesso di pubblicare aspetta lo stesso', function () {
    $this->c->update(['commenti_moderati' => false]);
    expect(ccmStatoDopoScrittura($this, $this->mario))->toBe('pubblicato');

    $ruolo = ccmRuolo('condòmino senza pubblica', ['Visualizza comunicazioni', 'Visualizza commenti comunicazioni', 'Commenta comunicazioni']);
    $this->paolo->syncRoles([$ruolo]);

    expect(ccmStatoDopoScrittura($this, $this->paolo->fresh()))->toBe('in_attesa')
        ->and(ccmStatoDopoScrittura($this, $this->admin, 'admin'))->toBe('pubblicato');
});

test('approva e nascondi: l\'amministratore sì; la collaboratrice, senza il permesso, no', function () {
    $primo = ccmCommentoSalvato($this->c, $this->mario, 'Primo', $this->aurora, null, 'in_attesa');
    $secondo = ccmCommentoSalvato($this->c, $this->paolo, 'Secondo', $this->aurora);

    $this->actingAs($this->collaboratrice)->post(route('admin.commenti.approva', $primo));
    $this->actingAs($this->collaboratrice)->post(route('admin.commenti.modera', $secondo));
    expect(Commento::find($primo)->stato)->toBe('in_attesa', 'La collaboratrice ha approvato senza il permesso')
        ->and(Commento::find($secondo)->stato)->toBe('pubblicato', 'La collaboratrice ha nascosto senza il permesso');

    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $primo));
    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $secondo));
    expect(Commento::find($primo)->stato)->toBe('pubblicato')
        ->and(Commento::find($secondo)->stato)->toBe('nascosto')
        ->and(Commento::find($primo)->moderato_da)->toBe($this->admin->id);
});

test('il permesso di approvare senza il pannello non approva niente', function () {
    $ruolo = ccmRuolo('condòmino con approva', ['Visualizza comunicazioni', 'Visualizza commenti comunicazioni', 'Approva commenti comunicazioni']);
    $this->paolo->syncRoles([$ruolo]);
    $id = ccmCommentoSalvato($this->c, $this->mario, 'In attesa', $this->aurora, null, 'in_attesa');

    $this->actingAs($this->paolo->fresh())->post(route('admin.commenti.approva', $id));

    expect(Commento::find($id)->stato)->toBe('in_attesa');
});

test('modifica ed elimina: il proprio sì; quello degli altri solo con il pannello e il permesso', function () {
    $diMario = ccmCommentoSalvato($this->c, $this->mario, 'Di Mario', $this->aurora);
    $diPaolo = ccmCommentoSalvato($this->c, $this->paolo, 'Di Paolo', $this->aurora);
    $diAnna = ccmCommentoSalvato($this->c, $this->collaboratrice, 'Di Anna', $this->aurora);

    $this->actingAs($this->mario)->patch(route('user.commenti.update', $diMario), ['corpo' => 'Di Mario, corretto']);
    $this->actingAs($this->mario)->patch(route('user.commenti.update', $diPaolo), ['corpo' => 'Cambiato da Mario']);
    $this->actingAs($this->collaboratrice)->patch(route('admin.commenti.update', $diPaolo), ['corpo' => 'Cambiato da Anna']);
    $this->actingAs($this->collaboratrice)->patch(route('admin.commenti.update', $diAnna), ['corpo' => 'Di Anna, corretto']);
    $this->actingAs($this->admin)->patch(route('admin.commenti.update', $diMario), ['corpo' => 'Corretto dallo studio']);

    expect(Commento::find($diMario)->corpo)->toBe('Corretto dallo studio')
        ->and(Commento::find($diPaolo)->corpo)->toBe('Di Paolo')
        ->and(Commento::find($diAnna)->corpo)->toBe('Di Anna, corretto');

    $this->actingAs($this->collaboratrice)->delete(route('admin.commenti.destroy', $diPaolo));
    $this->actingAs($this->mario)->delete(route('user.commenti.destroy', $diPaolo));
    $this->actingAs($this->collaboratrice)->delete(route('admin.commenti.destroy', $diAnna));
    $this->actingAs($this->mario)->delete(route('user.commenti.destroy', $diMario));

    expect(Commento::find($diPaolo))->not->toBeNull('Il commento di Paolo è stato eliminato da chi non poteva')
        ->and(Commento::find($diAnna))->toBeNull()
        ->and(Commento::find($diMario))->toBeNull();
});

test('le impostazioni dei commenti della comunicazione le cambia lo studio, dal pannello', function () {
    $this->actingAs($this->admin)
        ->patch(route('admin.comunicazioni.commenti.impostazioni', $this->c), ['can_comment' => false, 'commenti_moderati' => false])
        ->assertSessionHasNoErrors();
    $c = $this->c->fresh();
    expect((bool) $c->can_comment)->toBeFalse()
        ->and((bool) $c->commenti_moderati)->toBeFalse();

    $risposta = $this->actingAs($this->mario)
        ->patch(route('admin.comunicazioni.commenti.impostazioni', $this->c), ['can_comment' => true, 'commenti_moderati' => false]);
    expect(in_array($risposta->status(), [302, 403, 404], true) && ! (bool) $this->c->fresh()->can_comment)->toBeTrue(
        "Un condòmino ha cambiato le impostazioni dei commenti ({$risposta->status()})"
    );
});

test('un commento in attesa apre una voce nell\'Inbox che dice «comunicazione», con il palazzo e il collegamento alla scheda', function () {
    ccmScrivi($this, $this->mario, $this->c, ccmChiaveUnica(), 'Si può spostare?')->assertSessionHasNoErrors();

    $evento = Evento::query()
        ->where('tipo', 'da_moderare')
        ->where('eventable_type', $this->c->getMorphClass())
        ->where('eventable_id', $this->c->id)
        ->first();

    expect($evento)->not->toBeNull('Nessuna voce nell\'Inbox per il commento in attesa')
        ->and($evento->description)->toContain('Comunicazione')
        ->and($evento->description)->not->toContain('Segnalazione')
        ->and($evento->condomini()->pluck('condomini.id')->all())->toBe([$this->aurora->id]);

    // La prossima azione apre la pagina «Commenti» filtrata sulla comunicazione (decisione 445).
    $collegamento = $evento->meta['action_url'] ?? null;
    expect($collegamento)->not->toBeNull('La voce non porta alla pagina dei commenti')
        ->and($collegamento)->toBe('/admin/commenti?stato=in_attesa&tipo=comunicazioni&elemento=' . $this->c->id);
});
