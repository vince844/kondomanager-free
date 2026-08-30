<?php

use App\Services\Import\Rilievo;
use App\Services\Import\Severita;

/**
 * Il campo «foglio» di un rilievo.
 *
 * ⚠️ **Serve al modello compilabile a mano, che è un file solo con cinque fogli.** Là «Riga 14,
 * colonna «indirizzo»» esiste cinque volte, e il numero di riga — che `Rilievo` si è dato la
 * regola di rendere sempre uguale a quello che l'amministratore legge in Excel — torna a essere
 * ambiguo proprio mentre sembra preciso.
 *
 * Sta in coda al costruttore e alle tre factory: nessun chiamante esistente cambia, e i file di
 * Danea continuano a produrre rilievi con `foglio` nullo perché un foglio utile ce l'hanno sempre
 * e uno solo.
 */
it('resta nullo per chi non lo passa, cioè per tutti i parser di Danea', function () {
    $r = Rilievo::errore('prova.codice', 'messaggio', 'rimedio', 14, 'Saldo finale');

    expect($r->foglio)->toBeNull()
        ->and($r->riga)->toBe(14)
        ->and($r->colonna)->toBe('Saldo finale')
        ->and($r->toArray()['foglio'])->toBeNull();
});

it('lo porta su tutte e tre le severità', function () {
    expect(Rilievo::errore('a.b', 'm', null, 3, null, '2 persone')->foglio)->toBe('2 persone')
        ->and(Rilievo::avviso('a.b', 'm', null, 3, null, '3 tabelle')->foglio)->toBe('3 tabelle')
        ->and(Rilievo::daDecidere('a.b', 'm', null, 3, null, null, '1 unita')->foglio)->toBe('1 unita');
});

it('non sposta la chiave di decisione, che su daDecidere resta al suo posto', function () {
    // ⚠️ `daDecidere` ha sette parametri e il foglio è l'ottavo: se scivolasse al posto della
    // chiave di decisione, ogni rilievo «da decidere» diventerebbe irrisolvibile — il pulsante
    // di conferma resterebbe spento anche dopo che l'utente ha deciso tutto.
    $r = Rilievo::daDecidere('a.b', 'm', 'rimedio', 5, 'colonna', 'condominio:123', '0 copertina');

    expect($r->chiaveDecisione)->toBe('condominio:123')
        ->and($r->foglio)->toBe('0 copertina')
        ->and($r->severita)->toBe(Severita::DaDecidere);
});
