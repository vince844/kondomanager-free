<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Sala\LetturaDellaSala;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * 1.12.0-beta.13, terzo giro (C8, C9): il punto della sala porta il motivo della spesa sotto il titolo (decisione 581), e le pagine della sala, del verbale e
 * del registro dei verbali danno al menu del gestionale l'esercizio aperto (senza, i collegamenti andavano a «esercizi/undefined/…»).
 */
beforeEach(fn () => ConvocazioneDiViaRoma12::oggi());
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('il punto della sala dice «Perché era urgente: …» o «Perché supera il preventivo: …»; senza un motivo, niente', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $punti = $a->punti()->orderBy('numero')->get();
    $punti[0]->update(['dati' => ['motivazione' => 'Perdita dalla colonna dell\'acqua', 'motivazione_di' => 'urgenza']]);
    $punti[1]->update(['dati' => ['motivazione' => 'Il preventivo non copriva la facciata.', 'motivazione_di' => 'sforo']]);

    $lette = collect(app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['punti'] ?? [])->sortBy('numero')->values();
    expect($lette[0]['motivazione'])->toBe('Perché era urgente: Perdita dalla colonna dell\'acqua.')
        ->and($lette[1]['motivazione'])->toBe('Perché supera il preventivo: Il preventivo non copriva la facciata.')
        ->and($lette[2]['motivazione'])->toBeNull();
});

it('la sala, il verbale e il registro dei verbali portano l\'esercizio aperto per il menu del gestionale', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala', [$s->c, $a]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Sala')->where('esercizio.id', $s->e->id));
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro', [$s->c]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('esercizio.id', $s->e->id));
});
