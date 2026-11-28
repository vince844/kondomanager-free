<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # L'Inbox e le frasi dicono la stessa regola della scheda (Coda 696)
 *
 * Chi ha il pannello ma non «Visualizza segnalazioni» non può ricevere una segnalazione: il compito dell'Inbox lo mostra non attivo come la scheda, e
 * la frase di rifiuto e l'avviso «non attivo» non parlano solo del pannello.
 */

require_once __DIR__ . '/AssegnazioneScenario.php';

use App\Services\Gestionale\Inbox\CompitoDellaSegnalazione;

beforeEach(function () {
    asgPrepara($this);
});

test('nell\'Inbox chi la segue e perde «Visualizza segnalazioni» è non attivo, come nella scheda', function () {
    $ruolo = ccmRuolo('tecnico', ['Accesso pannello amministratore', 'Visualizza segnalazioni']);
    $tecnico = ccmStudioSenzaAnagrafica('Tito Tecnico', $ruolo);
    $s = asgAssegnata($this, $tecnico);
    $altra = asgAssegnata($this, $this->elena);

    expect(CompitoDellaSegnalazione::chiLeSegue([$s->id], $this->admin->id)[$s->id]['attivo'])->toBeTrue();

    \Spatie\Permission\Models\Role::findByName('tecnico')->revokePermissionTo('Visualizza segnalazioni');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $chi = CompitoDellaSegnalazione::chiLeSegue([$s->id, $altra->id], $this->admin->id);
    expect($chi[$s->id]['attivo'])->toBeFalse()
        ->and($chi[$altra->id]['attivo'])->toBeTrue();
});

test('la frase di rifiuto e l\'avviso «non attivo» non parlano solo del pannello', function () {
    foreach (['it' => 'vedere le segnalazioni', 'en' => 'view tickets', 'es' => 'ver los avisos', 'pt' => 'ver os tickets'] as $lingua => $vedere) {
        $rifiuto = __('segnalazioni.assignment.not_assignable', [], $lingua);
        $avviso = __('segnalazioni.assignment.inactive', ['name' => 'Tito'], $lingua);
        expect($rifiuto)->not->toBe('segnalazioni.assignment.not_assignable')
            ->and($rifiuto)->toContain($vedere)
            ->and($avviso)->not->toMatch('/panel|pannello/i', "{$lingua}: l'avviso parla solo del pannello");
    }
});
