<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Una ricevuta PEC con i parametri delle intestazioni senza le virgolette (`charset=UTF-8`, `boundary=confine`, `filename=daticert.xml`): è la forma più comune
 * nei messaggi veri, e la lettura del messaggio non si ferma su un errore. La fixture della convocazione li ha tutti fra virgolette.
 */

use App\Services\Posta\RicevutaPec;

it('i parametri senza virgolette si leggono: la ricevuta di consegna dà il giorno, l\'ora e l\'indirizzo', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><postacert tipo="avvenuta-consegna" errore="nessuno"><intestazione><mittente>studio@pec.it</mittente>'
        . '<oggetto>Prova</oggetto></intestazione><dati><gestore-emittente>Gestore di prova</gestore-emittente><data zona="+0100"><giorno>03/12/2026</giorno>'
        . '<ora>10:14:22</ora></data><identificativo>id-1</identificativo><consegna>neri.carla@pec.it</consegna></dati></postacert>';
    $eml = "From: posta-certificata@pec.gestore.it\r\nSubject: CONSEGNA: Prova\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=confine\r\n\r\n"
        . "--confine\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nRicevuta di avvenuta consegna\r\n"
        . "--confine\r\nContent-Type: application/xml; name=daticert.xml\r\nContent-Disposition: attachment; filename=daticert.xml\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($xml), 76, "\r\n") . "\r\n--confine--\r\n";

    $r = RicevutaPec::leggi($eml);

    expect($r)->not->toBeNull()
        ->and($r->eDiConsegna())->toBeTrue()
        ->and($r->consegnataA)->toBe('neri.carla@pec.it')
        ->and($r->quando->format('Y-m-d H:i'))->toBe('2026-12-03 10:14');
});
