<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il collegamento di un compito con il sito installato in una cartella (non nella radice del dominio): il compito salva il percorso senza la cartella, e il
 * pulsante lo apre con la cartella di oggi davanti; un indirizzo salvato intero, con la cartella, non la ripete. Senza la cartella ogni pulsante dei compiti
 * porterebbe a una pagina che non c'è.
 */

use App\Services\Gestionale\Inbox\IndirizzoDelCompito;
use Illuminate\Http\Request;

it('con il sito in una cartella il collegamento del compito ha la cartella di oggi, e un indirizzo salvato con la cartella non la ripete', function () {
    $richiesta = Request::create('http://localhost/kondo/public/admin/inbox', 'GET', [], [], [],
        ['SCRIPT_NAME' => '/kondo/public/index.php', 'SCRIPT_FILENAME' => base_path('public/index.php'), 'PHP_SELF' => '/kondo/public/index.php']);
    app()->instance('request', $richiesta);
    expect($richiesta->getBaseUrl())->toBe('/kondo/public');

    expect(IndirizzoDelCompito::perIlLink('/admin/gestionale/7/incassi/create'))->toBe('/kondo/public/admin/gestionale/7/incassi/create')
        ->and(IndirizzoDelCompito::percorso('http://localhost/kondo/public/admin/gestionale/7/incassi/create?x=1'))->toBe('/admin/gestionale/7/incassi/create?x=1')
        ->and(IndirizzoDelCompito::perIlLink('http://localhost/kondo/public/admin/gestionale/7/incassi/create'))->toBe('/kondo/public/admin/gestionale/7/incassi/create');
});
