<?php

/**
 * Come si chiama un'unità quando l'interno non c'è.
 *
 * ## Origine: reperto «alta» della Fase 1-bis della beta.58
 *
 * Rendendo l'interno facoltativo è emerso che in sei punti del programma **l'interno è l'unico
 * identificativo dell'unità**. Il peggiore è il PDF dell'estratto conto per anagrafica, cioè un
 * documento che l'amministratore consegna al condòmino: con l'interno vuoto stampava
 *
 *     Int.  (proprietario · 100%)
 *
 * nessun numero, nessun nome, nessun codice. Gli altri cinque: il riparto per capitoli, il selettore
 * della registrazione incassi (dove **la ricerca per nome non trovava l'unità**, perché filtrava su
 * `'Int. '`), la situazione debitoria, il widget della dashboard e lo scadenziario.
 *
 * ## La regola, e perché sta in un posto solo
 *
 * Ventiquattro punti costruivano quell'etichetta a mano, ognuno con la sua forma. È la stessa
 * divergenza del campo importo trovata il 17/08: nasce dalle copie. Qui la regola vive
 * nell'accessore del modello, e chi la vuole la chiede.
 *
 * L'ordine è: **l'interno se c'è, altrimenti il nome, altrimenti il codice** — perché il nome è
 * obbligatorio e quindi il ripiego esiste sempre, e il codice è l'ultima rete se un giorno anche il
 * nome diventasse facoltativo (cosa che oggi un test impedisce).
 */

use App\Models\Immobile;

it('con l\'interno lo usa, perché è il modo in cui gli amministratori chiamano le unità', function () {
    $i = new Immobile(['nome' => 'Appartamento 4', 'interno' => '4B']);

    expect($i->etichetta)->toBe('Int. 4B');
});

it('senza interno ripiega sul nome invece di lasciare «Int. » e basta', function () {
    $i = new Immobile(['nome' => 'Posto auto 3', 'interno' => '']);

    expect($i->etichetta)->toBe('Posto auto 3');
});

it('tratta il null come il vuoto: i due percorsi del modulo devono dare lo stesso esito', function () {
    // Dal modulo l'interno arriva come stringa vuota (per la conversione in `prepareForValidation`),
    // dal codice come `null`. Un'etichetta che distingue i due casi sarebbe un difetto invisibile.
    $vuoto = new Immobile(['nome' => 'Posto auto 3', 'interno' => '']);
    $nullo = new Immobile(['nome' => 'Posto auto 3', 'interno' => null]);

    expect($nullo->etichetta)->toBe($vuoto->etichetta);
});

it('con soli spazi ripiega comunque: uno spazio non identifica niente', function () {
    $i = new Immobile(['nome' => 'Posto auto 3', 'interno' => '   ']);

    expect($i->etichetta)->toBe('Posto auto 3');
});

it('senza interno e senza nome resta il codice, che è l\'ultima rete', function () {
    $i = new Immobile(['interno' => '', 'nome' => '']);
    $i->id = 613;

    expect($i->etichetta)->toBe('Unità #613');
});

it('l\'etichetta non è mai vuota, qualunque cosa manchi', function () {
    foreach ([['', ''], [null, null], ['  ', '  ']] as [$interno, $nome]) {
        $i = new Immobile(['nome' => $nome, 'interno' => $interno]);
        $i->id = 7;

        expect(trim($i->etichetta))->not->toBe('');
    }
});

it('dove prima si mostravano entrambi, l\'etichetta estesa li mostra ancora entrambi', function () {
    // ⚠️ Regressione trovata dal ripasso: applicando `etichetta` ovunque avevo tolto il nome dove
    // prima c'era. Su ogni installazione esistente l'interno c'è quasi sempre — era obbligatorio
    // fino a ieri — quindi la correzione del caso raro peggiorava il caso comune.
    $con = new Immobile(['nome' => 'Posto auto 3', 'interno' => '5']);
    $senza = new Immobile(['nome' => 'Posto auto 3', 'interno' => '']);

    expect($con->etichettaEstesa)->toBe('Int. 5 (Posto auto 3)')
        ->and($senza->etichettaEstesa)->toBe('Posto auto 3');
});

it('l\'etichetta estesa non lascia mai parentesi orfane', function () {
    $soloInterno = new Immobile(['nome' => '', 'interno' => '5']);

    expect($soloInterno->etichettaEstesa)->toBe('Int. 5')
        ->and($soloInterno->etichettaEstesa)->not->toContain('(');
});
