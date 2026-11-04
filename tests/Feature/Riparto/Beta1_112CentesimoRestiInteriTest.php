<?php

/**
 * Coda 301 (decisione 183.3): a pari resto il centesimo va a chi viene prima, e i resti si confrontano in aritmetica intera.
 *
 * Le due primitive dei resti maggiori, `MoneyHelper::ripartisciPerQuote` (passaggi, fermate, prospetto, saldi solidali, righe ad
 * personam) e `MoneyHelper::distribuisciPesiNormalizzati` (il riparto principale delle spese sulle tabelle), fino alla 1.11.0
 * confrontavano i resti in virgola mobile: con quote 10/30/60 su 6 centesimi i resti di x e di z, 0,6 tutti e due a mano, uscivano
 * 0,5999… e 0,6000…, e il centesimo andava a z qualunque fosse l'ordine.
 *
 * Ogni cifra viene dalla regola, non dal programma: le parti intere di `totale × quota / somma`, i centesimi che avanzano ai resti più
 * grandi, a pari resto al primo dell'array. `b112cEsatto()` la scrive in interi, con l'ordine per posizione esplicito.
 *
 * Cosa resta scoperto: l'ordine delle righe di titolarità che i chiamanti passano (lo fissa `Immobile::anagrafiche`, provato in
 * `tests/Feature/Gestionale/Beta1_112CentesimoPariRestoTest.php`); i chiamanti che leggono la pivot da sé.
 */

use App\Helpers\MoneyHelper;

/**
 * La regola in interi: `$numeratori` sono le quote come interi (somma positiva), `$totale` i centesimi (≥ 0). Parte intera e resto di
 * `totale × n / somma`; i centesimi che avanzano ai resti più grandi, a pari resto a chi sta prima nell'array.
 *
 * @param  array<string, int>  $numeratori
 * @return array<string, int>
 */
function b112cEsatto(int $totale, array $numeratori): array
{
    $somma = array_sum($numeratori);
    $parti = [];
    $resti = [];
    foreach ($numeratori as $k => $n) {
        $parti[$k] = intdiv($totale * $n, $somma);
        $resti[$k] = ($totale * $n) % $somma;
    }
    $avanzo = $totale - array_sum($parti);
    $chiavi = array_keys($numeratori);
    $posizione = array_flip($chiavi);
    usort($chiavi, fn ($a, $b) => [$resti[$b], $posizione[$a]] <=> [$resti[$a], $posizione[$b]]);
    foreach (array_slice($chiavi, 0, $avanzo) as $k) {
        $parti[$k]++;
    }

    return $parti;
}

/** Le terne di quote (percentuali) che sommano a 100, da una lista fissa: 42 terne. */
function b112cTerne(): array
{
    $quote = [10, 12.5, 16.67, 20, 25, 30, 33.33, 33.34, 40, 50, 60, 66.67, 75];
    $terne = [];
    foreach ($quote as $a) {
        foreach ($quote as $b) {
            foreach ($quote as $c) {
                if (abs($a + $b + $c - 100) < 0.001) {
                    $terne[] = [$a, $b, $c];
                }
            }
        }
    }

    return $terne;
}

it('Coda 301 — quote 10/30/60 su 6 centesimi: x 1, y 2, z 3, con tutte e due le primitive', function () {
    // 6 × 10/100 = 0,6; 6 × 30/100 = 1,8; 6 × 60/100 = 3,6. Parti intere 0 + 1 + 3 = 4, ne avanzano 2: il primo a y (resto 0,8), il
    // secondo fra x e z, pari a 0,6: a x, che viene prima.
    expect(MoneyHelper::ripartisciPerQuote(6, ['x' => 10.0, 'y' => 30.0, 'z' => 60.0]))->toBe(['x' => 1, 'y' => 2, 'z' => 3])
        ->and(MoneyHelper::distribuisciPesiNormalizzati(['x' => 0.1, 'y' => 0.3, 'z' => 0.6], 6))->toBe(['x' => 1, 'y' => 2, 'z' => 3]);
});

it('Coda 301 — a pari resto decide l\'ordine dell\'array: con z per primo il centesimo va a z', function () {
    // Gli stessi resti di sopra, l'ordine rovesciato: fra z e x, pari a 0,6, vince z che ora viene prima.
    expect(MoneyHelper::ripartisciPerQuote(6, ['z' => 60.0, 'y' => 30.0, 'x' => 10.0]))->toBe(['z' => 4, 'y' => 2, 'x' => 0])
        ->and(MoneyHelper::distribuisciPesiNormalizzati(['z' => 0.6, 'y' => 0.3, 'x' => 0.1], 6))->toBe(['z' => 4, 'y' => 2, 'x' => 0]);
});

it('Coda 301 — un credito si divide come un debito: -6 centesimi su 10/30/60 danno x -1, y -2, z -3', function () {
    expect(MoneyHelper::ripartisciPerQuote(-6, ['x' => 10.0, 'y' => 30.0, 'z' => 60.0]))->toBe(['x' => -1, 'y' => -2, 'z' => -3])
        ->and(MoneyHelper::distribuisciPesiNormalizzati(['x' => 0.1, 'y' => 0.3, 'z' => 0.6], -6))->toBe(['x' => -1, 'y' => -2, 'z' => -3]);
});

it('Coda 301 — € 1.000,04 su 10/30/60: x € 100,01, y € 300,01, z € 600,02', function () {
    // 100004 × 10/100 = 10000,4; × 30/100 = 30001,2; × 60/100 = 60002,4. Parti intere 100003, ne avanza 1: fra x e z, pari a 0,4, a x.
    expect(MoneyHelper::ripartisciPerQuote(100004, ['x' => 10.0, 'y' => 30.0, 'z' => 60.0]))->toBe(['x' => 10001, 'y' => 30001, 'z' => 60002])
        ->and(MoneyHelper::distribuisciPesiNormalizzati(['x' => 0.1, 'y' => 0.3, 'z' => 0.6], 100004))->toBe(['x' => 10001, 'y' => 30001, 'z' => 60002]);
});

it('Coda 301 — € 1.000.000,04 su 10/30/60: il pari resto resta un pari resto anche sui grandi importi', function () {
    // 100000004 × 10/100 = 10000000,4; × 30/100 = 30000001,2; × 60/100 = 60000002,4. Parti intere 100000003, ne avanza 1: fra x e z,
    // pari a 0,4, a x. Da un milione di euro in su la virgola mobile non tiene più l'ottava cifra del resto.
    expect(MoneyHelper::ripartisciPerQuote(100000004, ['x' => 10.0, 'y' => 30.0, 'z' => 60.0]))->toBe(['x' => 10000001, 'y' => 30000001, 'z' => 60000002])
        ->and(MoneyHelper::distribuisciPesiNormalizzati(['x' => 0.1, 'y' => 0.3, 'z' => 0.6], 100000004))->toBe(['x' => 10000001, 'y' => 30000001, 'z' => 60000002]);
});

it('Coda 301 — un resto più grande di un soffio vince anche quando la differenza è sotto il centomilionesimo', function () {
    // Pesi 1 e 1,00000001 su un centesimo: le parti esatte sono 0,49999999750… e 0,50000000250…, il resto più grande è il secondo.
    expect(MoneyHelper::ripartisciPerQuote(1, ['a' => 1.0, 'b' => 1.00000001]))->toBe(['a' => 0, 'b' => 1]);
});

it('Coda 301 — le 2.520 combinazioni (42 terne di quote, da 1 a 60 centesimi) seguono la regola in tutte e due le primitive', function () {
    $diversi = [];
    foreach (b112cTerne() as [$a, $b, $c]) {
        $numeratori = ['x' => (int) round($a * 100), 'y' => (int) round($b * 100), 'z' => (int) round($c * 100)];
        foreach (range(1, 60) as $totale) {
            $atteso = b112cEsatto($totale, $numeratori);
            $quote = MoneyHelper::ripartisciPerQuote($totale, ['x' => (float) $a, 'y' => (float) $b, 'z' => (float) $c]);
            $normalizzati = MoneyHelper::distribuisciPesiNormalizzati(['x' => $a / 100, 'y' => $b / 100, 'z' => $c / 100], $totale);
            if ($quote !== $atteso || $normalizzati !== $atteso) {
                $diversi[] = compact('a', 'b', 'c', 'totale', 'atteso', 'quote', 'normalizzati');
            }
        }
    }

    expect(count(b112cTerne()))->toBe(42)
        ->and($diversi)->toBe([]);
});

it('Coda 301 — le stesse 42 terne su importi da € 1.000,00 a € 1.000,59 seguono la regola', function () {
    $diversi = [];
    foreach (b112cTerne() as [$a, $b, $c]) {
        $numeratori = ['x' => (int) round($a * 100), 'y' => (int) round($b * 100), 'z' => (int) round($c * 100)];
        foreach (range(100000, 100059) as $totale) {
            $atteso = b112cEsatto($totale, $numeratori);
            $quote = MoneyHelper::ripartisciPerQuote($totale, ['x' => (float) $a, 'y' => (float) $b, 'z' => (float) $c]);
            $normalizzati = MoneyHelper::distribuisciPesiNormalizzati(['x' => $a / 100, 'y' => $b / 100, 'z' => $c / 100], $totale);
            if ($quote !== $atteso || $normalizzati !== $atteso) {
                $diversi[] = compact('a', 'b', 'c', 'totale', 'atteso', 'quote', 'normalizzati');
            }
        }
    }

    expect($diversi)->toBe([]);
});

it('Coda 301 — con pesi che non sono decimali (frazioni come 1/3 o 4/7) il pari resto va comunque al primo', function () {
    // I pesi normalizzati di `distribuisciPesiNormalizzati` sono quasi sempre frazioni: le terne di interi da 1 a 9, divise per la
    // loro somma, con i totali da 1 a 60. L'atteso è la regola sugli interi della terna.
    $diversi = [];
    foreach (range(1, 9) as $a) {
        foreach (range(1, 9) as $b) {
            foreach (range(1, 9) as $c) {
                $somma = $a + $b + $c;
                $pesi = ['x' => $a / $somma, 'y' => $b / $somma, 'z' => $c / $somma];
                foreach (range(1, 60) as $totale) {
                    $atteso = b112cEsatto($totale, ['x' => $a, 'y' => $b, 'z' => $c]);
                    $quote = MoneyHelper::ripartisciPerQuote($totale, $pesi);
                    $normalizzati = MoneyHelper::distribuisciPesiNormalizzati($pesi, $totale);
                    if ($quote !== $atteso || $normalizzati !== $atteso) {
                        $diversi[] = compact('a', 'b', 'c', 'totale', 'atteso', 'quote', 'normalizzati');
                    }
                }
            }
        }
    }

    expect($diversi)->toBe([]);
});

it('Coda 301 — due comproprietari al 50 % e un importo dispari: il centesimo al primo, in tutte e due le primitive', function () {
    expect(MoneyHelper::ripartisciPerQuote(10001, ['dora' => 50.0, 'anna' => 50.0]))->toBe(['dora' => 5001, 'anna' => 5000])
        ->and(MoneyHelper::ripartisciPerQuote(10001, ['anna' => 50.0, 'dora' => 50.0]))->toBe(['anna' => 5001, 'dora' => 5000])
        ->and(MoneyHelper::distribuisciPesiNormalizzati(['dora' => 0.5, 'anna' => 0.5], 10001))->toBe(['dora' => 5001, 'anna' => 5000]);
});

it('Coda 301 — millesimi a quattro decimali: 333,3333 + 333,3333 + 333,3334 su € 100,00', function () {
    // 10000 × 333,3333 / 1000 = 3333,333 due volte, 10000 × 333,3334 / 1000 = 3333,334: parti intere 9999, ne avanza 1 e va al
    // resto più grande (0,334), il terzo.
    expect(MoneyHelper::ripartisciPerQuote(10000, ['a' => 333.3333, 'b' => 333.3333, 'c' => 333.3334]))->toBe(['a' => 3333, 'b' => 3333, 'c' => 3334])
        ->and(MoneyHelper::distribuisciPesiNormalizzati(['a' => 0.3333333, 'b' => 0.3333333, 'c' => 0.3333334], 10000))->toBe(['a' => 3333, 'b' => 3333, 'c' => 3334]);
});

it('Coda 301 — un importo che non sta negli interi a 64 bit con i pesi in scala ripiega sulla via in virgola mobile e la somma torna', function () {
    // 10^13 centesimi per 6666667 (la quota 666,6667 in scala 10^4) supera PHP_INT_MAX: la via intera non si può fare.
    $totale = 10_000_000_000_000;
    $parti = MoneyHelper::ripartisciPerQuote($totale, ['a' => 333.3333, 'b' => 666.6667]);

    expect(array_sum($parti))->toBe($totale)
        ->and($parti)->toBe(['a' => 3_333_333_000_000, 'b' => 6_666_667_000_000]);
});

it('Coda 301 — pesi oltre 2^53, che in virgola mobile non sono più interi esatti: la via in virgola, e il pari resto al primo', function () {
    // 3 × 10^19 / (2 × 10^19) = 1,5 a testa: parti intere 1 e 1, ne avanza 1, pari resto, al primo.
    expect(MoneyHelper::ripartisciPerQuote(3, ['a' => 1e19, 'b' => 1e19]))->toBe(['a' => 2, 'b' => 1])
        ->and(MoneyHelper::ripartisciPerQuote(3, ['b' => 1e19, 'a' => 1e19]))->toBe(['b' => 2, 'a' => 1])
        // 2 × 20 / 38,5 = 1,039 e 2 × 18,5 / 38,5 = 0,961: parti intere 1 e 0, ne avanza 1 e va al resto più grande, b. Pesi così
        // non si convertono in interi senza perdere la proporzione.
        ->and(MoneyHelper::ripartisciPerQuote(2, ['a' => 2e19, 'b' => 1.85e19]))->toBe(['a' => 1, 'b' => 1]);
});

it('Coda 301 — pesi così piccoli che in scala sono tutti zero: la via in virgola, e l\'importo va a chi ha il peso', function () {
    // 5 × 0 / 10^-13 = 0 e 5 × 10^-13 / 10^-13 = 5.
    expect(MoneyHelper::ripartisciPerQuote(5, ['a' => 0.0, 'b' => 1e-13]))->toBe(['a' => 0, 'b' => 5]);
});

it('Coda 301 — pesi normalizzati che non sommano a 1 (fuori dal contratto): la parte di ognuno resta importo × peso, come prima', function () {
    // 10 × 0,3 = 3 a testa, parti intere 6, ne avanzano 4: un centesimo al più per chiave, come la funzione ha sempre fatto.
    expect(MoneyHelper::distribuisciPesiNormalizzati(['a' => 0.3, 'b' => 0.3], 10))->toBe(['a' => 4, 'b' => 4]);
});

it('Coda 301 — pesi tutti a zero: parti uguali, e il centesimo che avanza al primo', function () {
    expect(MoneyHelper::ripartisciPerQuote(10, ['a' => 0.0, 'b' => 0.0, 'c' => 0.0]))->toBe(['a' => 4, 'b' => 3, 'c' => 3]);
});

it('Coda 301 — pesi normalizzati con un negativo (la nota di credito): la proporzione resta, in interi', function () {
    // Righe -3000 e 1000 su -2000: pesi 1,5 e -0,5. -1000 × 1,5 = -1500, -1000 × -0,5 = 500.
    expect(MoneyHelper::distribuisciPesiNormalizzati(['grande' => 1.5, 'piccola' => -0.5], -1000))->toBe(['grande' => -1500, 'piccola' => 500])
        // Righe 300 e -100 su 200, totale 7: 7 × 1,5 = 10,5 e 7 × -0,5 = -3,5. Parti intere 10 e -4, ne avanza 1: pari resto 0,5, alla prima.
        ->and(MoneyHelper::distribuisciPesiNormalizzati(['grande' => 1.5, 'piccola' => -0.5], 7))->toBe(['grande' => 11, 'piccola' => -4]);
});

it('Coda 301 — pesi grandi che non sono decimali (importo × giorni / giorni del pezzo): il pari resto va comunque al primo', function () {
    // 26.500 / 3 e 1.500 su 6355: somma 31.000 / 3. 6355 × 26.500 / 31.000 = 5432,5 e 6355 × 4.500 / 31.000 = 922,5. Parti intere 6354,
    // ne avanza 1: pari resto 0,5, al primo dell'array.
    expect(MoneyHelper::ripartisciPerQuote(6355, ['a' => 26500 / 3, 'b' => 1500.0]))->toBe(['a' => 5433, 'b' => 922])
        ->and(MoneyHelper::ripartisciPerQuote(6355, ['b' => 1500.0, 'a' => 26500 / 3]))->toBe(['b' => 923, 'a' => 5432]);

    // La forma dei pesi di `ChiPagaAlPostoDellInquilino::dividiLaCoppia` con due pezzi: € 598,71 su 61 giorni e € 783,64 su 122, divisi
    // per giorni fra Pia (1 e 1), Olga (30 e 30) e Ugo (30 e 91). Pia 59871/61 + 78364/122 = 1623 + 50/61; Olga 59871 × 30/61 + 78364 × 30/122
    // = 48714 + 36/61; Ugo 59871 × 30/61 + 78364 × 91/122 = 87896 + 36/61. La somma dei pesi è € 1.382,35: parti intere 138233, ne avanzano 2,
    // a Pia (50/61) e, fra Olga e Ugo pari a 36/61, a Olga che viene prima.
    $pesi = ['pia' => 59871 * 1.0 / 61 + 78364 * 1.0 / 122, 'olga' => 59871 * 30.0 / 61 + 78364 * 30.0 / 122, 'ugo' => 59871 * 30.0 / 61 + 78364 * 91.0 / 122];
    expect(MoneyHelper::ripartisciPerQuote(138235, $pesi))->toBe(['pia' => 1624, 'olga' => 48715, 'ugo' => 87896]);

    // Le 729 terne della prova delle frazioni, con i pesi in scala da circa 4.000 a 90.000: l'atteso è la regola sugli interi della terna.
    $diversi = [];
    foreach (range(1, 9) as $a) {
        foreach (range(1, 9) as $b) {
            foreach (range(1, 9) as $c) {
                $somma = $a + $b + $c;
                $grandi = ['x' => 100000 * $a / $somma, 'y' => 100000 * $b / $somma, 'z' => 100000 * $c / $somma];
                foreach (range(1, 60) as $totale) {
                    $quote = MoneyHelper::ripartisciPerQuote($totale, $grandi);
                    if ($quote !== b112cEsatto($totale, ['x' => $a, 'y' => $b, 'z' => $c])) {
                        $diversi[] = compact('a', 'b', 'c', 'totale', 'quote');
                    }
                }
            }
        }
    }

    expect($diversi)->toBe([]);
});
