<?php

/**
 * B2, S1 — `InsiemePeriodi`: la competenza di un capitolo ordinario come insieme di tratti.
 *
 * Decisione 20 del progetto (`docs/subentro_e_competenza_temporale.md` §9): il riscaldamento in un
 * esercizio solare non è un intervallo, sono due — dal 1° gennaio al 15 aprile e dal 15 ottobre al 31
 * dicembre (zona E, DPR 74/2013). Un solo `PeriodoCompetenza` non lo rappresenta; un insieme ordinato
 * di tratti non sovrapposti sì, e il pro rata somma le sovrapposizioni tratto per tratto.
 *
 * Test puri, senza database: `InsiemePeriodi` e `PeriodoCompetenza` sono valori.
 *
 * Cosa NON coprono: come i tratti arrivano dal database (`competenze_capitolo`, S2) e come entrano nei
 * pesi del motore (S4). Qui c'è solo l'aritmetica dei giorni.
 */

use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;

it('due tratti della stagione di riscaldamento in un esercizio solare fanno 183 giorni, non 365 e non 105', function () {
    $stagione = new InsiemePeriodi(
        new PeriodoCompetenza('2026-01-01', '2026-04-15'),
        new PeriodoCompetenza('2026-10-15', '2026-12-31'),
    );

    expect($stagione->giorni())->toBe(105 + 78)
        ->and(count($stagione))->toBe(2)
        ->and($stagione->dal()->toDateString())->toBe('2026-01-01')
        ->and($stagione->al()->toDateString())->toBe('2026-12-31');
});

it('i tratti si ordinano da soli per data, comunque siano stati dichiarati', function () {
    $insieme = new InsiemePeriodi(
        new PeriodoCompetenza('2026-10-15', '2026-12-31'),
        new PeriodoCompetenza('2026-01-01', '2026-04-15'),
    );

    expect($insieme->periodi()[0]->dal->toDateString())->toBe('2026-01-01')
        ->and($insieme->periodi()[1]->dal->toDateString())->toBe('2026-10-15');
});

it('due tratti che si sovrappongono sono un errore di chi li ha dichiarati, non un caso da sommare due volte', function () {
    expect(fn () => new InsiemePeriodi(
        new PeriodoCompetenza('2026-01-01', '2026-06-30'),
        new PeriodoCompetenza('2026-06-30', '2026-12-31'),
    ))->toThrow(InvalidArgumentException::class);
});

it('un insieme senza tratti non esiste: «nessuna riga» lo decide il risolutore, non un insieme vuoto', function () {
    expect(fn () => new InsiemePeriodi())->toThrow(InvalidArgumentException::class);
});

it('la sovrapposizione con il periodo di un titolare si somma tratto per tratto: chi vende il 30 aprile ha pagato tutto il primo tratto e nulla del secondo', function () {
    $stagione = new InsiemePeriodi(
        new PeriodoCompetenza('2026-01-01', '2026-04-15'),
        new PeriodoCompetenza('2026-10-15', '2026-12-31'),
    );
    $venditore = new PeriodoCompetenza('2026-01-01', '2026-04-30');
    $acquirente = new PeriodoCompetenza('2026-05-01', '2026-12-31');

    expect($stagione->giorniDiSovrapposizione($venditore))->toBe(105)
        ->and($stagione->giorniDiSovrapposizione($acquirente))->toBe(78)
        // I due si completano: nessun giorno perso, nessuno contato due volte.
        ->and($stagione->giorniDiSovrapposizione($venditore) + $stagione->giorniDiSovrapposizione($acquirente))->toBe($stagione->giorni());
});

it('un inquilino che entra a giugno, in un esercizio 01/10–30/09 con gas dal 1° ottobre al 1° maggio, non ha un solo giorno di sovrapposizione (scenario 2 di Leonardo)', function () {
    $gas = InsiemePeriodi::uno(new PeriodoCompetenza('2025-10-01', '2026-05-01'));
    $nuovoInquilino = new PeriodoCompetenza('2026-06-01', '2026-09-30');
    $vecchioInquilino = new PeriodoCompetenza('2025-10-01', '2026-05-31');

    expect($gas->giorniDiSovrapposizione($nuovoInquilino))->toBe(0)
        ->and($gas->giorniDiSovrapposizione($vecchioInquilino))->toBe($gas->giorni());
});

it('la sovrapposizione fra due insiemi somma le intersezioni di ogni coppia di tratti', function () {
    $a = new InsiemePeriodi(new PeriodoCompetenza('2026-01-01', '2026-03-31'), new PeriodoCompetenza('2026-07-01', '2026-09-30'));
    $b = new InsiemePeriodi(new PeriodoCompetenza('2026-03-01', '2026-07-31'));

    // marzo (31) + luglio (31)
    expect($a->giorniDiSovrapposizione($b))->toBe(62)
        ->and($b->giorniDiSovrapposizione($a))->toBe(62);
});

it('contiene() risponde tratto per tratto: il 1° maggio non è nella stagione di riscaldamento', function () {
    $stagione = new InsiemePeriodi(new PeriodoCompetenza('2026-01-01', '2026-04-15'), new PeriodoCompetenza('2026-10-15', '2026-12-31'));

    expect($stagione->contiene('2026-04-15'))->toBeTrue()
        ->and($stagione->contiene('2026-05-01'))->toBeFalse()
        ->and($stagione->contiene('2026-10-15'))->toBeTrue();
});

it('PeriodoCompetenza::intersezione() dà il tratto comune, o nulla se non si toccano', function () {
    $a = new PeriodoCompetenza('2026-01-01', '2026-06-30');
    $b = new PeriodoCompetenza('2026-05-01', '2026-12-31');
    $c = new PeriodoCompetenza('2026-07-01', '2026-07-31');

    expect($a->intersezione($b)?->toArray())->toBe(['dal' => '2026-05-01', 'al' => '2026-06-30'])
        ->and($a->intersezione($c))->toBeNull()
        // Estremi inclusi: due periodi che si toccano in un giorno lo condividono.
        ->and($a->intersezione(new PeriodoCompetenza('2026-06-30', '2026-07-31'))?->giorni())->toBe(1);
});

// 1.11.0-beta.47, giro sulle correzioni: i giorni di una voce del prospetto che somma pezzi della stessa persona. Non copre: il prospetto
// (lo provano i test del prospetto), insiemi con più di due tratti per parte.
it('unione() fonde i tratti che si sovrappongono o si toccano, e lascia distinti quelli disgiunti', function () {
    $luglioDicembre = InsiemePeriodi::uno(new PeriodoCompetenza('2026-07-01', '2026-12-31'));
    $febbraio = new InsiemePeriodi(new PeriodoCompetenza('2026-02-01', '2026-02-28'), new PeriodoCompetenza('2026-06-01', '2026-12-31'));

    // Gli stessi 184 giorni due volte restano 184 (1/7–31/12: 31 + 31 + 30 + 31 + 30 + 31), non 368.
    expect($luglioDicembre->unione($luglioDicembre)->giorni())->toBe(184)
        // Febbraio (28) e giugno–dicembre (30 + 184 = 214) con luglio–dicembre dentro: 28 + 214 = 242.
        ->and($febbraio->unione($luglioDicembre)->giorni())->toBe(242)
        ->and($febbraio->unione($luglioDicembre)->toArray())->toBe([['dal' => '2026-02-01', 'al' => '2026-02-28'], ['dal' => '2026-06-01', 'al' => '2026-12-31']])
        // Due tratti che si toccano (31/3 e 1/4) diventano uno: 90 + 30 = 120 giorni, 1/1–30/4.
        ->and(InsiemePeriodi::uno(new PeriodoCompetenza('2026-01-01', '2026-03-31'))->unione(InsiemePeriodi::uno(new PeriodoCompetenza('2026-04-01', '2026-04-30')))->toArray())
            ->toBe([['dal' => '2026-01-01', 'al' => '2026-04-30']])
        // Disgiunti: 1/1–31/3 (90) e 1/10–31/12 (92) restano due, 182 giorni.
        ->and(InsiemePeriodi::uno(new PeriodoCompetenza('2026-10-01', '2026-12-31'))->unione(InsiemePeriodi::uno(new PeriodoCompetenza('2026-01-01', '2026-03-31')))->giorni())->toBe(182);
});
