<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Si assegna solo a chi apre la segnalazione (Coda 696)
 *
 * Per ricevere una segnalazione servono «Accesso pannello amministratore», «Visualizza segnalazioni» e un account non sospeso: senza il secondo la scheda
 * risponde 403 e la segnalazione non è nei suoi elenchi. Chi non può ricevere non è nella tendina, la rotta lo rifiuta e non parte nessuna email; chi la
 * segue già e non ha più il permesso compare nel filtro come non attivo.
 *
 * La catena: menu «Assegna a», assegnazione, email, scheda aperta (200) e segnalazione nell'elenco.
 */

require_once __DIR__ . '/AssegnazioneScenario.php';

use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    asgPrepara($this);
});

/** Il «Tecnico» di prova: il pannello senza «Visualizza segnalazioni». */
function asvTecnico($test, array $permessi = []): \App\Models\User
{
    $ruolo = ccmRuolo('tecnico', array_merge(['Accesso pannello amministratore'], $permessi));

    return ccmStudioSenzaAnagrafica('Tito Tecnico', $ruolo);
}

test('chi ha il pannello ma non vede le segnalazioni non è fra gli assegnabili e non può ricevere', function () {
    $tecnico = asvTecnico($this);
    $regole = ASG_REGOLE;

    expect($regole::puoRicevere($tecnico))->toBeFalse()
        ->and(collect($regole::assegnabili())->pluck('id'))->not->toContain($tecnico->id)
        ->and(collect($regole::assegnabili())->pluck('id'))->toContain($this->elena->id);
});

test('la rotta rifiuta di assegnargliela e non parte nessuna email', function () {
    $tecnico = asvTecnico($this);

    asgAssegna($this, $this->admin, $this->s, $tecnico->id)->assertSessionHasErrors('assigned_to');

    expect(asgRiga($this->s)->assigned_to)->toBeNull();
    Notification::assertNotSentTo($tecnico, ASG_MAIL_ASSEGNATA);
});

test('con «Visualizza segnalazioni» si assegna: l\'email parte, la scheda si apre e la segnalazione è nel suo elenco', function () {
    $tecnico = asvTecnico($this, ['Visualizza segnalazioni']);

    asgAssegna($this, $this->admin, $this->s, $tecnico->id)->assertSessionHasNoErrors();

    expect((int) asgRiga($this->s)->assigned_to)->toBe($tecnico->id);
    Notification::assertSentTo($tecnico, ASG_MAIL_ASSEGNATA);

    $this->actingAs($tecnico->fresh())->get(route('admin.segnalazioni.show', $this->s))->assertOk();
    $elenco = asgElenco(asgProps($this->actingAs($tecnico->fresh())->get(route('admin.segnalazioni.index'))));
    expect(collect($elenco)->pluck('id'))->toContain($this->s->id);
});

test('chi la segue e perde «Visualizza segnalazioni» resta scritto e risulta non attivo', function () {
    $tecnico = asvTecnico($this, ['Visualizza segnalazioni']);
    $s = asgAssegnata($this, $tecnico);
    \Spatie\Permission\Models\Role::findByName('tecnico')->revokePermissionTo('Visualizza segnalazioni');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $regole = ASG_REGOLE;

    $nomi = collect($regole::assegnatari())->keyBy('id');
    expect($nomi->get($tecnico->id)['attivo'] ?? null)->toBeFalse()
        ->and($nomi->get($this->elena->id)['attivo'] ?? null)->toBeTrue();

    $scheda = asgRisorsa(asgProps($this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $s)))['segnalazione']);
    expect($scheda['assegnata_a']['attivo'])->toBeFalse();
});
