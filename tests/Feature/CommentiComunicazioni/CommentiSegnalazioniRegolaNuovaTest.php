<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti delle segnalazioni: la regola nuova (Coda 558, decisioni 261.5, 268.2 e 268.3)
 *
 * Oggi `CommentoPolicy::before()` dà ogni azione sui commenti a chi ha il pannello: la collaboratrice approva, nasconde,
 * modifica ed elimina i commenti di tutti, anche senza i permessi. Da adesso approvare, nascondere, modificare ed eliminare
 * i commenti degli altri vogliono il pannello e il permesso insieme, come per gli allegati. «Consenti commenti» spento
 * chiude la discussione a tutti, studio compreso. L'avviso di un commento in attesa va a tutti quelli che lo possono
 * approvare, non a chi ha solo il pannello (decisione 282.1); l'approvazione avvisa anche chi partecipa, non solo l'autore.
 * Ogni segnalazione ha la sua moderazione, come ogni comunicazione (decisione 281).
 */

use App\Enums\Role;
use App\Models\Commento;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use App\Notifications\Commenti\NuovoCommentoNotification;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->s = ccmSegnalazione($this->aurora, $this->mario);
});

function ccmCommentiDellaSegnalazione($s): \Illuminate\Support\Collection
{
    return Commento::query()->where('commentable_type', $s->getMorphClass())->where('commentable_id', $s->id)->orderBy('id')->get();
}

test('la collaboratrice, senza il permesso, non approva e non nasconde i commenti delle segnalazioni', function () {
    $attesa = ccmCommentoSalvato($this->s, $this->paolo, 'In attesa', $this->aurora, null, 'in_attesa');
    $pubblicato = ccmCommentoSalvato($this->s, $this->paolo, 'Pubblicato', $this->aurora);

    $this->actingAs($this->collaboratrice)->post(route('admin.commenti.approva', $attesa));
    $this->actingAs($this->collaboratrice)->post(route('admin.commenti.modera', $pubblicato));

    expect(Commento::find($attesa)->stato)->toBe('in_attesa')
        ->and(Commento::find($pubblicato)->stato)->toBe('pubblicato');

    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $attesa));
    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $pubblicato));
    expect(Commento::find($attesa)->stato)->toBe('pubblicato')
        ->and(Commento::find($pubblicato)->stato)->toBe('nascosto');
});

test('la collaboratrice modifica ed elimina i suoi commenti, non quelli degli altri', function () {
    $diMario = ccmCommentoSalvato($this->s, $this->mario, 'Di Mario', $this->aurora);
    $diAnna = ccmCommentoSalvato($this->s, $this->collaboratrice, 'Di Anna', $this->aurora);
    $diAnnaDaEliminare = ccmCommentoSalvato($this->s, $this->collaboratrice, 'Di Anna, da togliere', $this->aurora);

    $this->actingAs($this->collaboratrice)->patch(route('admin.commenti.update', $diMario), ['corpo' => 'Cambiato da Anna']);
    $this->actingAs($this->collaboratrice)->delete(route('admin.commenti.destroy', $diMario));
    $this->actingAs($this->collaboratrice)->patch(route('admin.commenti.update', $diAnna), ['corpo' => 'Di Anna, corretto']);
    $this->actingAs($this->collaboratrice)->delete(route('admin.commenti.destroy', $diAnnaDaEliminare));

    expect(Commento::find($diMario)?->corpo)->toBe('Di Mario', 'La collaboratrice ha toccato il commento di Mario')
        ->and(Commento::find($diAnna)->corpo)->toBe('Di Anna, corretto')
        ->and(Commento::find($diAnnaDaEliminare))->toBeNull('La collaboratrice non ha potuto eliminare un suo commento');
});

test('il permesso di approvare senza il pannello non approva niente', function () {
    $ruolo = ccmRuolo('condòmino con approva segnalazioni', ['Visualizza segnalazioni', 'Visualizza commenti segnalazioni', 'Approva commenti segnalazioni']);
    $this->paolo->syncRoles([$ruolo]);
    $id = ccmCommentoSalvato($this->s, $this->mario, 'In attesa', $this->aurora, null, 'in_attesa');

    $this->actingAs($this->paolo->fresh())->post(route('admin.commenti.approva', $id));

    expect(Commento::find($id)->stato)->toBe('in_attesa');
});

test('con «Consenti commenti» spento non scrive nemmeno lo studio; i commenti che ci sono restano', function () {
    ccmCommentoSalvato($this->s, $this->mario, 'Scritto prima', $this->aurora);
    $this->s->update(['can_comment' => false]);

    $this->actingAs($this->admin)->post(route('admin.segnalazioni.commenti.store', $this->s), ['corpo' => 'Dallo studio']);
    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $this->s), ['corpo' => 'Dal portale']);

    expect(ccmCommentiDellaSegnalazione($this->s)->pluck('corpo')->all())->toBe(['Scritto prima']);
});

test('con «Consenti commenti» acceso lo studio scrive anche senza un\'anagrafica nel palazzo', function () {
    $studioEsterno = ccmUtente([], 'Studio Esterno', Role::AMMINISTRATORE->value);

    $this->actingAs($studioEsterno)->post(route('admin.segnalazioni.commenti.store', $this->s), ['corpo' => 'Mando il tecnico'])->assertSessionHasNoErrors();

    expect(ccmCommentiDellaSegnalazione($this->s)->pluck('corpo')->all())->toBe(['Mando il tecnico'])
        ->and(ccmCommentiDellaSegnalazione($this->s)->first()->stato)->toBe('pubblicato');
});

test('l\'avviso di un commento in attesa va a tutti quelli che lo possono approvare, anche senza un\'anagrafica nel palazzo; non a chi ha solo il pannello', function () {
    $studioBorea = ccmUtente([$this->borea->id], 'Studio Borea', Role::AMMINISTRATORE->value);
    $studioSenzaAnagrafica = ccmStudioSenzaAnagrafica('Studio Senza Anagrafica');
    ccmModerazione($this->s, true);

    $this->actingAs($this->paolo)->post(route('user.segnalazioni.commenti.store', $this->s), ['corpo' => 'Anche da me'])->assertSessionHasNoErrors();

    expect(ccmCommentiDellaSegnalazione($this->s)->first()->stato)->toBe('in_attesa')
        ->and(ccmDestinatari(CommentoInAttesaNotification::class))->toBe(ccmIds($this->admin, $studioBorea, $studioSenzaAnagrafica));
});

test('ogni segnalazione ha la sua moderazione: accesa, il condòmino aspetta e lo studio esce subito; spenta, esce subito anche lui', function () {
    ccmModerazione($this->s, true);
    $this->actingAs($this->paolo)->post(route('user.segnalazioni.commenti.store', $this->s), ['corpo' => 'Con la moderazione'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.commenti.store', $this->s), ['corpo' => 'Dallo studio'])->assertSessionHasNoErrors();
    ccmModerazione($this->s, false);
    $this->actingAs($this->paolo)->post(route('user.segnalazioni.commenti.store', $this->s), ['corpo' => 'Senza la moderazione'])->assertSessionHasNoErrors();

    expect(ccmCommentiDellaSegnalazione($this->s)->pluck('stato', 'corpo')->all())->toBe([
        'Con la moderazione' => 'in_attesa',
        'Dallo studio' => 'pubblicato',
        'Senza la moderazione' => 'pubblicato',
    ]);
});

test('le impostazioni dei commenti della segnalazione le cambia lo studio, dal pannello', function () {
    $this->actingAs($this->admin)
        ->patch(route('admin.segnalazioni.commenti.impostazioni', $this->s), ['can_comment' => false, 'commenti_moderati' => true])
        ->assertSessionHasNoErrors();
    $s = $this->s->fresh();
    expect((bool) $s->can_comment)->toBeFalse()
        ->and((bool) $s->commenti_moderati)->toBeTrue();

    $risposta = $this->actingAs($this->mario)
        ->patch(route('admin.segnalazioni.commenti.impostazioni', $this->s), ['can_comment' => true, 'commenti_moderati' => false]);
    expect(in_array($risposta->status(), [302, 403, 404], true) && ! (bool) $this->s->fresh()->can_comment)->toBeTrue(
        "Un condòmino ha cambiato le impostazioni dei commenti ({$risposta->status()})"
    );
});

test('una segnalazione nuova prende la moderazione dall\'impostazione generale delle segnalazioni, spenta di serie', function () {
    $nuova = ccmSegnalazione($this->aurora, $this->mario);
    expect((bool) $nuova->commenti_moderati)->toBeFalse();

    $impostazioni = app(\App\Settings\GeneralSettings::class);
    $impostazioni->commenti_segnalazioni_moderati_di_serie = true;
    $impostazioni->save();
    expect((bool) ccmSegnalazione($this->aurora, $this->mario)->commenti_moderati)->toBeTrue();
});

test('l\'approvazione di un commento della segnalazione avvisa anche chi partecipa', function () {
    ccmCommentoSalvato($this->s, $this->paolo, 'Anche da me', $this->aurora);
    $id = ccmCommentoSalvato($this->s, $this->mario, 'Ancora rotto', $this->aurora, null, 'in_attesa');

    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $id));

    expect(Commento::find($id)->stato)->toBe('pubblicato')
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->toContain($this->paolo->id)
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($this->admin->id)
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($this->mario->id);
});
