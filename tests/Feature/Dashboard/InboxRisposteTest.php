<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le tre risposte su tutti i compiti dell'Inbox, anche quelli di oggi: «Fatto, con una nota», «Non serve, con il motivo», «Ricordamelo il…» (la rotta
 * `inbox.risposta`). «Fatto» fa quello che faceva «Completa» (chiude l'evento) e scrive la risposta; «Non serve» chiude con il motivo, e sul bonifico segnalato
 * dal portale è il «Rifiuta» di oggi, con lo stesso effetto sull'evento del condòmino. La risposta sta nella tabella delle risposte, con il titolo e il tipo del
 * compito copiati. Le rotte di oggi («Completa» e «Rifiuta») passano dalla stessa funzione e danno lo stesso di prima. Chi può: «Modifica scadenza in agenda».
 */

use App\Enums\Permission;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Models\User;
use App\Services\Gestionale\RispostePromemoria;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

function irtRisposta(Evento $e): ?RispostaPromemoria
{
    return RispostaPromemoria::where('chiave', RispostePromemoria::chiaveDelCompito((int) $e->id))->first();
}

it('«Fatto» chiude il compito di ogni tipo e scrive la risposta, con la nota facoltativa, il titolo e il tipo copiati', function (string $tipo) {
    $e = icsCompito($this, $tipo);
    icsRispondi($this, $e, ['tipo' => 'fatto', 'nota' => 'fatto al telefono'])->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', 'Fatto: il compito è chiuso.');
    $r = irtRisposta($e);

    expect($e->fresh()->is_completed)->toBeTrue()
        ->and($e->fresh()->completed_at)->not->toBeNull()
        ->and($r->argomento)->toBe('compito')
        ->and($r->evento_id)->toBe($e->id)
        ->and($r->condominio_id)->toBe($this->condominio->id)
        ->and($r->titolo)->toBe('Compito ' . $tipo)
        ->and($r->tipo_compito)->toBe($tipo)
        ->and($r->stato)->toBe('fatto')
        ->and($r->nota)->toBe('fatto al telefono')
        ->and($r->registro[0])->toMatchArray(['tipo' => 'fatto', 'utente' => 'Vincenzo', 'nota' => 'fatto al telefono']);

    // Senza la nota: la nota è facoltativa per i compiti dell'agenda.
    $altro = icsCompito($this, $tipo, [], 'Altro ' . $tipo);
    icsRispondi($this, $altro, ['tipo' => 'fatto'])->assertSessionHasNoErrors();
    expect($altro->fresh()->is_completed)->toBeTrue();
// Il compito di una segnalazione di guasto ha solo «Ricordamelo»: «Fatto» e «Non serve» si rifiutano (`ActionInboxSegnalazioniTest.php`).
})->with(array_values(array_diff(array_merge(ICS_SI_SPOSTA, ICS_A_PARTE), ['segnalazione_guasto'])));

it('«Non serve» chiude il compito di ogni tipo con il motivo obbligatorio', function (string $tipo) {
    $e = icsCompito($this, $tipo);
    icsRispondi($this, $e, ['tipo' => 'non_serve', 'motivo' => ''])->assertSessionHasErrors(['motivo' => 'Scrivi perché non serve.']);
    expect($e->fresh()->is_completed)->toBeFalse();

    icsRispondi($this, $e, ['tipo' => 'non_serve', 'motivo' => 'già fatto da un collega'])->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', 'Non serve: il compito è chiuso.');
    expect($e->fresh()->is_completed)->toBeTrue()
        ->and(irtRisposta($e)->stato)->toBe('non_serve')
        ->and(irtRisposta($e)->nota)->toBe('già fatto da un collega');
})->with(array_values(array_diff(array_merge(ICS_SI_SPOSTA, ICS_A_PARTE), ['verifica_pagamento', 'segnalazione_guasto'])));

it('sul bonifico segnalato «Non serve» è il «Rifiuta» di oggi: sull\'evento del condòmino lo stato, il motivo e il giorno; «Fatto» non lo tocca', function () {
    [$compito, $rata] = icsBonifico($this);
    icsRispondi($this, $compito, ['tipo' => 'non_serve', 'motivo' => ''])->assertSessionHasErrors(['motivo' => 'Scrivi perché rifiuti la segnalazione: il condòmino lo legge.']);
    icsRispondi($this, $compito, ['tipo' => 'non_serve', 'motivo' => 'Bonifico non ancora arrivato'])->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', 'Segnalazione rifiutata: il condòmino legge il motivo nel portale.');

    expect($compito->fresh()->is_completed)->toBeTrue()
        ->and($rata->fresh()->meta['status'])->toBe('rejected')
        ->and($rata->fresh()->meta['rejection_reason'])->toBe('Bonifico non ancora arrivato')
        ->and($rata->fresh()->meta['rejected_at'])->not->toBeNull()
        ->and(irtRisposta($compito)->stato)->toBe('non_serve');

});

it('sul bonifico segnalato «Fatto» si accetta solo dopo l\'incasso: con la segnalazione ancora in verifica il rifiuto con la frase, e il compito resta aperto', function () {
    [$compito, $rata] = icsBonifico($this);
    icsRispondi($this, $compito, ['tipo' => 'fatto'])
        ->assertSessionHasErrors(['tipo' => 'Prima registra l\'incasso con «Registra l\'incasso», o rifiuta la segnalazione con «Non serve».']);
    expect($compito->fresh()->is_completed)->toBeFalse()->and($rata->fresh()->meta['status'])->toBe('reported')
        ->and(irtRisposta($compito))->toBeNull();

    // La riga lo dice al menu: «Fatto» è spento, con la frase.
    $riga = collect($this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props']['tasks']['data'])->firstWhere('id', $compito->id);
    expect($riga['fatto_spento'])->toBe('Prima registra l\'incasso con «Registra l\'incasso», o rifiuta la segnalazione con «Non serve».');

    // Un incasso, anche parziale, cambia lo stato della segnalazione: allora «Fatto» chiude, e l'evento del condòmino non cambia.
    $rata->update(['meta' => array_merge($rata->meta, ['status' => 'partial'])]);
    $riga = collect($this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props']['tasks']['data'])->firstWhere('id', $compito->id);
    expect($riga['fatto_spento'])->toBeNull();
    icsRispondi($this, $compito, ['tipo' => 'fatto'])->assertSessionHasNoErrors();
    expect($compito->fresh()->is_completed)->toBeTrue()->and($rata->fresh()->meta['status'])->toBe('partial');
});

it('le rotte di oggi danno lo stesso di prima e scrivono la risposta: «Completa» è «Fatto» senza nota, «Rifiuta» è «Non serve» del bonifico', function () {
    $e = icsCompito($this, 'controllo_incassi');
    $this->actingAs($this->admin)->from(route('admin.inbox'))->patch(route('admin.inbox.complete', $e))->assertRedirect()->assertSessionHas('success', 'Task completato.');
    expect($e->fresh()->is_completed)->toBeTrue()->and(irtRisposta($e)->stato)->toBe('fatto')->and(irtRisposta($e)->nota)->toBeNull();
    // Un compito già chiuso: come oggi, il messaggio e niente altro.
    $this->actingAs($this->admin)->from(route('admin.inbox'))->patch(route('admin.inbox.complete', $e))->assertSessionHas('error', 'Impossibile completare questo task.');
    expect(irtRisposta($e)->registro)->toHaveCount(1);

    [$compito, $rata] = icsBonifico($this);
    $this->actingAs($this->admin)->from(route('admin.inbox'))->post(route('admin.inbox.reject', $compito), ['reason' => 'Non arrivato'])
        ->assertRedirect()->assertSessionHas('success', 'Segnalazione rifiutata e condòmino notificato.');
    expect($compito->fresh()->is_completed)->toBeTrue()
        ->and($rata->fresh()->meta['status'])->toBe('rejected')
        ->and($rata->fresh()->meta['rejection_reason'])->toBe('Non arrivato')
        ->and(irtRisposta($compito)->stato)->toBe('non_serve');
});

it('403 senza «Modifica scadenza in agenda», prima di ogni altra verifica', function () {
    $e = icsCompito($this, 'controllo_incassi');
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_EVENTS->value]);
    $chi = User::factory()->create(['email_verified_at' => now()]);
    $chi->assignRole('Su misura');

    icsRispondi($this, $e, ['tipo' => 'fatto'], $chi->fresh())->assertForbidden();
    icsRispondi($this, $e, ['tipo' => 'non_serve'], $chi->fresh())->assertForbidden();
    expect($e->fresh()->is_completed)->toBeFalse()->and(RispostaPromemoria::count())->toBe(0);
});

it('404 per un compito privato, già chiuso o che non chiede un\'azione', function () {
    [, $rata] = icsBonifico($this);
    $chiuso = icsCompito($this, 'controllo_incassi');
    $chiuso->update(['is_completed' => true, 'completed_at' => now()]);
    $senzaAzione = icsCompito($this, 'scadenza', ['requires_action' => false]);

    foreach ([$rata, $chiuso, $senzaAzione] as $e) {
        icsRispondi($this, $e, ['tipo' => 'fatto'])->assertNotFound();
        icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertNotFound();
    }
    expect(RispostaPromemoria::count())->toBe(0)->and($rata->fresh()->meta['status'])->toBe('reported');
});

it('un tipo di risposta che non c\'è è un rifiuto', function () {
    $e = icsCompito($this, 'controllo_incassi');
    icsRispondi($this, $e, ['tipo' => 'riapri'])->assertSessionHasErrors('tipo');
    expect($e->fresh()->is_completed)->toBeFalse();
});
