<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 467: nella card della scheda della comunicazione, nel pannello, i destinatari: i condomìni per nome, le persone per nome, o
 * «Privata, con lo studio». Il portale non li riceve: il condòmino non vede gli altri condomìni dello studio (Coda 668).
 */

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

test('nel pannello: una comunicazione ai palazzi porta i condomìni per nome, in ordine', function () {
    $c = ccmComunicazione([$this->borea, $this->aurora], $this->admin);

    expect(ccmScheda($this, $this->admin, $c, 'admin')['destinatari'])
        ->toBe(['tipo' => 'condomini', 'totale' => 2, 'nomi' => ['Condominio Aurora', 'Condominio Borea']]);
});

test('nel pannello: una comunicazione a persone porta le persone per nome', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['is_private' => true], [$this->mario, $this->paolo]);

    $destinatari = ccmScheda($this, $this->admin, $c, 'admin')['destinatari'];
    expect($destinatari['tipo'])->toBe('persone')
        ->and($destinatari['totale'])->toBe(2)
        ->and($destinatari['nomi'])->toHaveCount(2);
});

test('nel pannello: una privata senza persone è «Privata, con lo studio»', function () {
    $c = ccmComunicazione([$this->aurora], $this->mario, ['is_private' => true]);

    expect(ccmScheda($this, $this->admin, $c, 'admin')['destinatari'])->toBe(['tipo' => 'privata', 'totale' => 0, 'nomi' => []]);
});

test('il portale non riceve i destinatari', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);

    $props = ccmScheda($this, $this->mario, $c, 'user');
    expect($props)->not->toHaveKey('destinatari')
        ->and(json_encode($props))->not->toContain('Condominio Borea');
});
