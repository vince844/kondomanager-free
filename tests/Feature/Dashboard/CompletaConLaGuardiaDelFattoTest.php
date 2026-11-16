<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Completa» (`inbox.complete`, la rotta di prima che la pagina non chiama più) è «Fatto» senza nota, e ne ha le guardie: sul bonifico segnalato ancora in
 * verifica, senza un incasso, risponde con la frase di «Fatto» e niente cambia (il compito resta aperto, la rata resta «in verifica»); dopo un incasso chiude.
 */

use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Gestionale\RispostePromemoria;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

it('«Completa» non chiude il bonifico segnalato ancora in verifica: la frase di «Fatto», e niente cambia', function () {
    [$compito, $rata] = icsBonifico($this);

    $this->actingAs($this->admin)->from(route('admin.inbox'))->patch(route('admin.inbox.complete', $compito))
        ->assertSessionHasErrors(['tipo' => RispostePromemoria::FRASE_PRIMA_L_INCASSO]);

    expect($compito->fresh()->is_completed)->toBeFalse()
        ->and($rata->fresh()->meta['status'])->toBe('reported')
        ->and(RispostaPromemoria::count())->toBe(0);
});

it('dopo un incasso, anche parziale, «Completa» chiude il bonifico segnalato come «Fatto»', function () {
    [$compito, $rata] = icsBonifico($this);
    $rata->update(['meta' => array_merge($rata->meta, ['status' => 'partial'])]);

    $this->actingAs($this->admin)->from(route('admin.inbox'))->patch(route('admin.inbox.complete', $compito))->assertSessionHas('success', 'Task completato.');

    expect($compito->fresh()->is_completed)->toBeTrue()->and($rata->fresh()->meta['status'])->toBe('partial');
});
