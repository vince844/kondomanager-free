<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il calendario dell'accordo di rientro, puro: quanto è versato di ogni rata (i versamenti coprono le rate in ordine, la più vecchia prima, art. 1193
 * c.c.), una rata con anche un centesimo scoperto è «non versata» (nessuna tolleranza), e il primo giorno in cui la regola di decadenza scritta
 * nell'accordo si verifica, nelle due forme («N rate del calendario non versate, anche non consecutive» e «G giorni dopo una rata non versata»).
 *
 * Il caso, rifatto a mano: l'accordo di Neri del 15/07/2026 per € 225,92 in tre rate, € 225,92 / 3 = 75,3066… → 75,31 + 75,31 + 75,30 (l'ultima prende i
 * centesimi: 225,92 − 2 × 75,31 = 75,30), il 31/07, il 31/08 e il 30/09. Neri versa € 75,31 il 31/07 e € 75,30 il 30/09. Dopo il 30/09 i versati sono
 * 75,31 + 75,30 = 150,61, contro 75,31 + 75,31 = 150,62 delle prime due: rata 1 versata, rata 2 versati € 75,30 di € 75,31 (manca € 0,01), rata 3 non
 * versata. «15 giorni dopo una rata non versata»: la rata 2 scade il 31/08, il quindicesimo giorno dopo è il 15/09, la condizione c'è il 16/09.
 * «Due rate non versate»: il 01/10 la rata 3 (30/09) è scaduta e non versata, e la rata 2 lo è per un centesimo.
 */

use App\Services\Gestionale\Debiti\CalendarioAccordo;

/** Il calendario del caso. */
function cacCalendario(): array
{
    return [
        ['numero' => 1, 'data' => '2026-07-31', 'importo' => 7531],
        ['numero' => 2, 'data' => '2026-08-31', 'importo' => 7531],
        ['numero' => 3, 'data' => '2026-09-30', 'importo' => 7530],
    ];
}

/** Il versato all'accordo alla fine di un giorno, dai versamenti dati [giorno => centesimi, anche negativi]. */
function cacVersatoAl(array $versamenti): \Closure
{
    return function (string $giorno) use ($versamenti): int {
        $v = 0;
        foreach ($versamenti as $data => $importo) {
            if ($data <= $giorno) {
                $v += $importo;
            }
        }

        return $v;
    };
}

it('i versamenti coprono le rate del calendario in ordine: dopo il 30/09 rata 1 versata, rata 2 versati € 75,30 di € 75,31, rata 3 non versata', function () {
    $rate = CalendarioAccordo::rate(cacCalendario(), 7531 + 7530, '2026-10-01');

    expect(array_map(fn ($r) => [$r['numero'], $r['versato'], $r['mancano'], $r['versata'], $r['scaduta']], $rate))->toBe([
        [1, 7531, 0, true, true],
        [2, 7530, 1, false, true],
        [3, 0, 7530, false, true],
    ]);
    // Il 30/09 la rata 3 scade quel giorno: non è ancora scaduta.
    expect(CalendarioAccordo::rate(cacCalendario(), 15061, '2026-09-30')[2]['scaduta'])->toBeFalse()
        // Versato più del calendario: ogni rata versata, il resto non va da nessuna parte.
        ->and(array_column(CalendarioAccordo::rate(cacCalendario(), 30000, '2026-10-01'), 'versata'))->toBe([true, true, true]);
});

it('la forma «15 giorni dopo una rata non versata» si verifica il 16/09, con la rata 2; la forma «due rate» il 01/10, con «manca € 0,01» sulla rata 2', function () {
    $versato = cacVersatoAl(['2026-07-31' => 7531, '2026-09-30' => 7530]);
    $segnali = CalendarioAccordo::segnali(cacCalendario(), 2, 15, $versato, ['2026-07-31', '2026-09-30'], '2026-07-15', '2026-10-05');

    expect($segnali['giorni'])->toBe(['il' => '2026-09-16', 'rate' => [['numero' => 2, 'data' => '2026-08-31', 'importo' => 7531, 'versato' => 0]]])
        ->and($segnali['rate'])->toBe(['il' => '2026-10-01', 'rate' => [
            ['numero' => 2, 'data' => '2026-08-31', 'importo' => 7531, 'versato' => 7530],
            ['numero' => 3, 'data' => '2026-09-30', 'importo' => 7530, 'versato' => 0],
        ]]);

    // Fino al 30/09 compreso la forma «due rate» non c'è ancora; fino al 15/09 nemmeno l'altra.
    expect(CalendarioAccordo::segnali(cacCalendario(), 2, 15, $versato, ['2026-07-31', '2026-09-30'], '2026-07-15', '2026-09-30')['rate'])->toBeNull()
        ->and(CalendarioAccordo::segnali(cacCalendario(), 2, 15, $versato, ['2026-07-31', '2026-09-30'], '2026-07-15', '2026-09-15'))->toBe(['rate' => null, 'giorni' => null]);
});

it('un versamento che salda dopo non cambia il primo giorno: i segnali sono quelli di prima', function () {
    $versato = cacVersatoAl(['2026-07-31' => 7531, '2026-09-30' => 7530, '2026-10-15' => 7531]);
    $segnali = CalendarioAccordo::segnali(cacCalendario(), 2, 15, $versato, ['2026-07-31', '2026-09-30', '2026-10-15'], '2026-07-15', '2026-10-20');

    expect($segnali['giorni']['il'])->toBe('2026-09-16')->and($segnali['rate']['il'])->toBe('2026-10-01');
});

it('un versamento tornato indietro dopo che le rate erano versate fa nascere il segnale il giorno dello storno', function () {
    // Le tre rate versate alla scadenza; il 20/10 il versamento del 31/08 torna indietro: i versati sono 75,31 + 75,30 = 150,61, in ordine la rata 2
    // manca di € 0,01 e la rata 3 di € 75,30 dal 20/10, e il 20/10 è più di 15 giorni dopo il 31/08 (50) e dopo il 30/09 (20).
    $versato = cacVersatoAl(['2026-07-31' => 7531, '2026-08-31' => 7531, '2026-09-30' => 7530, '2026-10-20' => -7531]);
    $segnali = CalendarioAccordo::segnali(cacCalendario(), null, 15, $versato, ['2026-07-31', '2026-08-31', '2026-09-30', '2026-10-20'], '2026-07-15', '2026-10-31');

    expect($segnali)->toBe(['rate' => null, 'giorni' => ['il' => '2026-10-20', 'rate' => [
        ['numero' => 2, 'data' => '2026-08-31', 'importo' => 7531, 'versato' => 7530],
        ['numero' => 3, 'data' => '2026-09-30', 'importo' => 7530, 'versato' => 0],
    ]]]);
});

it('senza una regola non c\'è nessun segnale; la forma «una rata» si verifica il giorno dopo la prima rata scoperta', function () {
    $versato = cacVersatoAl([]);
    expect(CalendarioAccordo::segnali(cacCalendario(), null, null, $versato, [], '2026-07-15', '2026-12-31'))->toBe(['rate' => null, 'giorni' => null])
        ->and(CalendarioAccordo::segnali(cacCalendario(), 1, null, $versato, [], '2026-07-15', '2026-12-31')['rate']['il'])->toBe('2026-08-01')
        // Zero giorni: il giorno dopo la scadenza, come «una rata».
        ->and(CalendarioAccordo::segnali(cacCalendario(), null, 0, $versato, [], '2026-07-15', '2026-12-31')['giorni']['il'])->toBe('2026-08-01');
});

it('la somma del calendario deve fare il debito riconosciuto: € 225,91 contro € 225,92 è rifiutata con le due cifre e la differenza', function () {
    expect(CalendarioAccordo::sommaCheNonTorna([7531, 7531, 7530], 22592))->toBeNull()
        ->and(CalendarioAccordo::sommaCheNonTorna([7531, 7530, 7530], 22592))
        ->toBe('La somma delle rate del calendario è € 225,91 e il debito riconosciuto è € 225,92: correggi una rata, la differenza è € 0,01.')
        ->and(CalendarioAccordo::sommaCheNonTorna([7531, 7531, 7531], 22592))
        ->toBe('La somma delle rate del calendario è € 225,93 e il debito riconosciuto è € 225,92: correggi una rata, la differenza è € 0,01.');
});

it('il quindicesimo giorno non basta: con un versamento di € 10,00 il 15/09 sulla rata 2 la forma «15 giorni» si verifica il 16/09, non il 15/09', function () {
    $versato = cacVersatoAl(['2026-07-31' => 7531, '2026-09-15' => 1000]);
    $segnali = CalendarioAccordo::segnali(cacCalendario(), null, 15, $versato, ['2026-07-31', '2026-09-15'], '2026-07-15', '2026-09-30');

    expect($segnali['giorni'])->toBe(['il' => '2026-09-16', 'rate' => [['numero' => 2, 'data' => '2026-08-31', 'importo' => 7531, 'versato' => 1000]]]);
});
