<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, Coda 186: `/condomini/options` era dichiarata senza
 * alcun middleware, e la copriva solo l'ordine di dichiarazione (`Route::resource('/condomini')`
 * stava prima e intercettava «options» come se fosse un `{condominio}`). Bastava che qualcuno
 * spostasse le due rotte di posto perché un ospite ricevesse l'elenco completo dei condomìni.
 * `CondominioController::options()` e `useCondominiOptions.ts` (mai importato) sono stati tolti
 * insieme alla rotta.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('un ospite su condomini/options non riceve mai l\'elenco dei condomìni', function () {
    // La rotta «options» non esiste più: chi ci arriva incontra il resource `/condomini/{condominio}`,
    // che sta prima in dichiarazione e tratta «options» come un id di condominio — e nega
    // l'accesso all'ospite prima ancora di provare a risolverlo (`auth` gira prima del model
    // binding). Non è più possibile, in nessun ordine di dichiarazione, che questa risposta sia un
    // 200 con l'elenco JSON dei condomìni: è quello il difetto che la Coda 186 correggeva.
    $response = $this->getJson('/condomini/options');

    $response->assertStatus(401);

    $paginaWeb = $this->get('/condomini/options');
    $paginaWeb->assertRedirect(route('login'));
});
