<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La nota di licenza (Coda 493, decisione 687): l'indirizzo dei termini del servizio è un'impostazione dell'istanza, vuota di serie, che la finestra
 * «Note legali» usa per dire «senza alcuna garanzia, salvo quelle dei termini del servizio». Arriva anche alle pagine per ospiti (la pagina di accesso
 * mostra la nota), e solo se è un indirizzo web: altro non diventa un collegamento.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function terminiDellaPagina($test): mixed
{
    $props = $test->get(route('login'))->assertOk()->viewData('page')['props'];
    expect($props)->toHaveKey('termini_del_servizio_url');

    return $props['termini_del_servizio_url'];
}

it('di serie l\'impostazione è vuota e la pagina di accesso non porta nessun indirizzo', function () {
    config(['kondomanager.termini_del_servizio_url' => null]);

    expect(terminiDellaPagina($this))->toBeNull();
});

it('impostata con un indirizzo web arriva alla pagina di accesso, anche a un ospite', function () {
    config(['kondomanager.termini_del_servizio_url' => 'https://esempio.test/termini']);

    expect(terminiDellaPagina($this))->toBe('https://esempio.test/termini');
});

it('un valore che non è un indirizzo web non arriva alla pagina', function () {
    config(['kondomanager.termini_del_servizio_url' => 'javascript:alert(1)']);

    expect(terminiDellaPagina($this))->toBeNull();
});
