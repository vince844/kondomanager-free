<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Gestionale\Accessori\InteressiDiMora;

/**
 * Il calcolo degli interessi di mora, da solo: i tratti fra un movimento e l'altro, il tasso e il divisore dell'anno, i crediti che coprono le rate
 * più vecchie, l'arrotondamento al centesimo rata per rata. Ogni cifra è rifatta a mano qui sotto, accanto al caso.
 *
 * I giorni di un tratto vanno dal giorno dopo l'evento al giorno dell'evento dopo compreso: un versamento del 30/04 conta il 30/04 sul capitale di
 * prima. Interessi in centesimi = capitale × tasso × giorni / divisore, con il tasso in decimillesimi di punto (1,60 % = 16.000).
 */

/** Il tasso di un giorno: legale dell'anno dalla tabella data, con la frase quando manca. */
function imTassoLegale(array $tabella = [2024 => 25000, 2025 => 20000, 2026 => 16000]): Closure
{
    return function (string $giorno) use ($tabella) {
        $anno = (int) substr($giorno, 0, 4);
        if (! isset($tabella[$anno])) {
            return 'Manca il tasso legale del ' . $anno . '.';
        }

        return ['tasso_e4' => $tabella[$anno], 'etichetta' => number_format($tabella[$anno] / 10000, 2, ',', '.') . ' %', 'fonte' => 'tasso legale'];
    };
}

function imChiave(string $inizio, string $scadenza, bool $base = true, int $numero = 1): array
{
    return ['inizio' => $base ? $inizio : null, 'base' => $base, 'ordine' => [$scadenza, $numero === 0 ? 0 : 1, $numero]];
}

it('Neri: € 300,00 scaduti il 31/03, € 100,00 versati il 30/04, al 29/06: tratti di 30 e 60 giorni, € 0,92', function () {
    // 300,00 × 1,60 % × 30/365 = 0,394520 + 200,00 × 1,60 % × 60/365 = 0,526027 = 0,920548 → € 0,92.
    $esito = InteressiDiMora::calcola(
        ['A' => imChiave('2026-04-01', '2026-03-31')],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione'],
            ['giorno' => '2026-04-30', 'chiave' => 'A', 'importo' => -10000, 'causa' => 'versamento']],
        imTassoLegale(), [], '2026-06-29');

    $a = $esito['A'];
    expect($a['interessi'])->toBe(92)->and($a['fermo'])->toBeNull()
        ->and(array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['capitale'], $t['tasso_e4'], $t['divisore'], $t['esatti'], $t['causa']], $a['tratti']))->toBe([
            ['2026-04-01', '2026-04-30', 30, 30000, 16000, 365, 3945, 'scadenza'],
            ['2026-05-01', '2026-06-29', 60, 20000, 16000, 365, 5260, 'versamento'],
        ]);
});

it('senza il versamento: € 300,00 per 90 giorni, € 1,18', function () {
    // 300,00 × 1,60 % × 90/365 = 1,183562 → € 1,18.
    $esito = InteressiDiMora::calcola(['A' => imChiave('2026-04-01', '2026-03-31')],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione']], imTassoLegale(), [], '2026-06-29');

    expect($esito['A']['interessi'])->toBe(118)->and(count($esito['A']['tratti']))->toBe(1)->and($esito['A']['tratti'][0]['giorni'])->toBe(90);
});

it('l\'esempio di Gabriele: arrotondati rata per rata 3,78 + 0,85 = 4,63, non 4,64 sul totale', function () {
    // 750,00 × 1,60 % × 115/365 = 3,780822 → 3,78; 750,00 × 1,60 % × 26/365 = 0,854795 → 0,85; insieme 4,635616, che sul totale sarebbe 4,64.
    $esito = InteressiDiMora::calcola(
        ['A' => imChiave('2026-02-01', '2026-01-31'), 'B' => imChiave('2026-05-01', '2026-04-30', true, 2)],
        [['giorno' => '2026-01-10', 'chiave' => 'A', 'importo' => 75000, 'causa' => 'emissione'],
            ['giorno' => '2026-04-10', 'chiave' => 'B', 'importo' => 75000, 'causa' => 'emissione']],
        imTassoLegale(), [], '2026-05-26');

    expect($esito['A']['interessi'])->toBe(378)->and($esito['B']['interessi'])->toBe(85)
        ->and($esito['A']['tratti'][0]['giorni'])->toBe(115)->and($esito['B']['tratti'][0]['giorni'])->toBe(26)
        ->and($esito['A']['interessi'] + $esito['B']['interessi'])->toBe(463);
});

it('un tratto a cavallo del 2025 e del 2026: 2,0 % per i giorni del 2025, 1,6 % per quelli del 2026', function () {
    // 1.000,00 × 2,00 % × 31/365 = 1,698630 (dicembre 2025) + 1.000,00 × 1,60 % × 31/365 = 1,358904 (gennaio 2026) = 3,057534 → € 3,06.
    $esito = InteressiDiMora::calcola(['A' => imChiave('2025-12-01', '2025-11-30')],
        [['giorno' => '2025-10-01', 'chiave' => 'A', 'importo' => 100000, 'causa' => 'emissione']], imTassoLegale(), [], '2026-01-31');

    expect(array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['tasso_e4'], $t['esatti'], $t['causa']], $esito['A']['tratti']))->toBe([
        ['2025-12-01', '2025-12-31', 31, 20000, 16986, 'scadenza'],
        ['2026-01-01', '2026-01-31', 31, 16000, 13589, 'anno'],
    ])->and($esito['A']['interessi'])->toBe(306);
});

it('il 2028 è bisestile: il divisore è 366', function () {
    // Un tasso scritto del 3,00 % dal 01/01/2027. 1.000,00 × 3,00 % × 60/366 = 4,918033 → € 4,92 (su 365 sarebbero 4,931507 → € 4,93).
    $tasso = fn (string $g) => ['tasso_e4' => 30000, 'etichetta' => '3,00 %', 'fonte' => 'regolamento'];
    $esito = InteressiDiMora::calcola(['A' => imChiave('2028-01-01', '2027-12-31')],
        [['giorno' => '2027-11-01', 'chiave' => 'A', 'importo' => 100000, 'causa' => 'emissione']], $tasso, [], '2028-02-29');

    expect($esito['A']['tratti'][0]['divisore'])->toBe(366)->and($esito['A']['tratti'][0]['giorni'])->toBe(60)->and($esito['A']['interessi'])->toBe(492);
});

it('prima della tabella del tasso legale la rata si ferma tutta, con la frase: niente interessi a metà', function () {
    $esito = InteressiDiMora::calcola(['A' => imChiave('2018-12-01', '2018-11-30')],
        [['giorno' => '2018-10-01', 'chiave' => 'A', 'importo' => 100000, 'causa' => 'emissione']], imTassoLegale([2019 => 8000]), [], '2019-01-31');

    expect($esito['A']['interessi'])->toBe(0)->and($esito['A']['tratti'])->toBe([])->and($esito['A']['fermo'])->toBe('Manca il tasso legale del 2018.');
});

it('un anticipo che copre la rata dal giorno dopo: un giorno solo, € 0,01', function () {
    // € 300,00 versati il 01/04 senza dire a che cosa, rimasti fra gli anticipi: coprono la rata dal 02/04. 300,00 × 1,60 % × 1/365 = 0,013151 → € 0,01.
    $esito = InteressiDiMora::calcola(
        ['A' => imChiave('2026-04-01', '2026-03-31'), 'anticipi' => imChiave('', '', false)],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione'],
            ['giorno' => '2026-04-01', 'chiave' => 'anticipi', 'importo' => -30000, 'causa' => 'versamento']],
        imTassoLegale(), [], '2026-06-29');

    expect($esito['A']['interessi'])->toBe(1)->and(count($esito['A']['tratti']))->toBe(1)->and($esito['A']['tratti'][0]['giorni'])->toBe(1)
        ->and($esito)->not->toHaveKey('anticipi');
});

it('un credito su un\'altra rata copre prima la rata più vecchia, dal giorno dopo', function () {
    // La rata 2 (scade il 30/06) è versata il 15/05 con € 400,00 su € 300,00: € 100,00 di credito coprono la rata 1 dal 16/05.
    // 300,00 × 1,60 % × 45/365 = 0,591781 + 200,00 × 1,60 % × 45/365 = 0,394521 = 0,986302 → € 0,99.
    $esito = InteressiDiMora::calcola(
        ['A' => imChiave('2026-04-01', '2026-03-31'), 'B' => imChiave('2026-07-01', '2026-06-30', true, 2)],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione'],
            ['giorno' => '2026-01-15', 'chiave' => 'B', 'importo' => 30000, 'causa' => 'emissione'],
            ['giorno' => '2026-05-15', 'chiave' => 'B', 'importo' => -40000, 'causa' => 'versamento']],
        imTassoLegale(), [], '2026-06-29');

    expect(array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['capitale'], $t['causa']], $esito['A']['tratti']))->toBe([
        ['2026-04-01', '2026-05-15', 45, 30000, 'scadenza'],
        ['2026-05-16', '2026-06-29', 45, 20000, 'credito'],
    ])->and($esito['A']['interessi'])->toBe(99)->and($esito['B']['interessi'])->toBe(0);
});

it('un versamento tornato indietro spezza il tratto: il denaro conta versato fino allo storno, e la riga lo dice', function () {
    // 300,00 × 30/365 + 200,00 × 31/365 + 300,00 × 29/365, all'1,60 %: 0,394521 + 0,271781 + 0,381370 = 1,047672 → € 1,05.
    $esito = InteressiDiMora::calcola(['A' => imChiave('2026-04-01', '2026-03-31')],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione'],
            ['giorno' => '2026-04-30', 'chiave' => 'A', 'importo' => -10000, 'causa' => 'versamento'],
            ['giorno' => '2026-05-31', 'chiave' => 'A', 'importo' => 10000, 'causa' => 'storno']],
        imTassoLegale(), [], '2026-06-29');

    expect(array_map(fn ($t) => [$t['giorni'], $t['capitale'], $t['causa']], $esito['A']['tratti']))->toBe([
        [30, 30000, 'scadenza'], [31, 20000, 'versamento'], [29, 30000, 'storno'],
    ])->and($esito['A']['interessi'])->toBe(105);
});

it('una rata fuori dalla base (un accessorio, la quota di un inquilino) non prende interessi, e il suo credito copre lo stesso', function () {
    $esito = InteressiDiMora::calcola(
        ['A' => imChiave('2026-04-01', '2026-03-31'), 'X' => imChiave('', '2026-03-31', false)],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione'],
            ['giorno' => '2026-03-01', 'chiave' => 'X', 'importo' => 9200, 'causa' => 'emissione']],
        imTassoLegale(), [], '2026-06-29');

    expect($esito)->not->toHaveKey('X')->and($esito['A']['interessi'])->toBe(118);
});

it('una regola nuova a metà spezza il tratto: il tasso di ogni giorno è quello della regola di quel giorno', function () {
    // Legale 1,60 % fino al 30/06, poi 3,00 % scritto dal 01/07. Rata di € 300,00 scaduta il 31/03, al 31/07:
    // 300,00 × 1,60 % × 91/365 = 1,196712 + 300,00 × 3,00 % × 31/365 = 0,764384 = 1,961096 → € 1,96.
    $tasso = fn (string $g) => $g >= '2026-07-01' ? ['tasso_e4' => 30000, 'etichetta' => '3,00 %', 'fonte' => 'regolamento']
        : ['tasso_e4' => 16000, 'etichetta' => '1,60 %', 'fonte' => 'tasso legale'];
    $esito = InteressiDiMora::calcola(['A' => imChiave('2026-04-01', '2026-03-31')],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione']], $tasso, ['2026-07-01'], '2026-07-31');

    expect(array_map(fn ($t) => [$t['giorni'], $t['tasso_e4'], $t['causa']], $esito['A']['tratti']))->toBe([[91, 16000, 'scadenza'], [31, 30000, 'regola']])
        ->and($esito['A']['interessi'])->toBe(196);
});

it('ogni tratto conserva la regola che gli ha dato il tasso: con lo stesso tasso, una regola nuova spezza il tratto', function () {
    // Senza regole fino al 30/06, dal 01/07 una regola con il tasso legale: lo stesso 1,60 %, due tratti. 300,00 × 1,60 % × 122/365 = 1,604384 → € 1,60.
    $tasso = fn (string $g) => ['tasso_e4' => 16000, 'etichetta' => '1,60 %', 'fonte' => 'tasso legale', 'regola_id' => $g >= '2026-07-01' ? 4 : null];
    $esito = InteressiDiMora::calcola(['A' => imChiave('2026-04-01', '2026-03-31')],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione']], $tasso, ['2026-07-01'], '2026-07-31');

    expect(array_map(fn ($t) => [$t['giorni'], $t['regola_id'], $t['causa']], $esito['A']['tratti']))->toBe([[91, null, 'scadenza'], [31, 4, 'regola']])
        ->and($esito['A']['interessi'])->toBe(160);
});

it('il resto a metà centesimo va su: l\'arrotondamento è al centesimo più vicino, il mezzo in su', function () {
    // 18.250 centesimi × 1,00 % × 1/365 = esattamente 0,5 centesimi: va su, 1 centesimo.
    $uno = InteressiDiMora::calcola(['A' => imChiave('2026-04-01', '2026-03-31')],
        [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 18250, 'causa' => 'emissione']],
        fn () => ['tasso_e4' => 10000, 'etichetta' => '1,00 %', 'fonte' => 'regolamento'], [], '2026-04-01');

    expect($uno['A']['interessi'])->toBe(1)->and($uno['A']['tratti'][0]['esatti'])->toBe(50);
});
