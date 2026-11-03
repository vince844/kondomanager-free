<?php

/**
 * B2, S5 — `ProRataTemporis`: il conguaglio di una quota emessa fra uscente ed entrante (D9).
 * Ogni centesimo atteso è calcolato a mano nel commento, come in `MotoreTemporaleTest`.
 */

use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use App\Support\ProRataTemporis;

it('ordinario, esercizio solare, rogito il 1° maggio: € 412,00 si divide 120/245 — 13.545 all\'uscente e 27.655 all\'entrante, il centesimo al resto maggiore', function () {
    // 41200 × 120/365 = 13545,21 → 13545; 41200 × 245/365 = 27654,79 → 27654; resta 1 → .79 vince → 27655.
    $r = ProRataTemporis::dividi(41200, new PeriodoCompetenza('2026-01-01', '2026-12-31'), '2026-05-01');

    expect($r)->toBe(['uscente' => 13545, 'entrante' => 27655, 'giorni_uscente' => 120, 'giorni_entrante' => 245, 'giorni_periodo' => 365]);
});

it('a pari resto il centesimo va all\'uscente, prima chiave inserita (la convenzione dell\'invariante 6): € 1.000,01 a metà esatta di un periodo di 182 giorni', function () {
    // 100001 × 91/182 = 50000,5 per entrambi → floor 50000 + 50000 = 100000, resta 1 → all'uscente.
    $r = ProRataTemporis::dividi(100001, new PeriodoCompetenza('2026-01-01', '2026-07-01'), '2026-04-02');

    expect($r['giorni_uscente'])->toBe(91)->and($r['giorni_entrante'])->toBe(91)
        ->and($r['uscente'])->toBe(50001)->and($r['entrante'])->toBe(50000);
});

it('con due tratti (zona E) contano i giorni dei tratti, non le date estreme: rogito il 1° maggio → 105 giorni all\'uscente, 78 all\'entrante', function () {
    // 01/01–15/04 = 105 giorni, 15/10–31/12 = 78 giorni; la decorrenza cade nel buco fra i due.
    // 100001 × 105/183 = 57377,59 → 57377; × 78/183 = 42623,41 → 42623; resta 1 → .59 vince → 57378.
    $zonaE = new InsiemePeriodi(new PeriodoCompetenza('2026-01-01', '2026-04-15'), new PeriodoCompetenza('2026-10-15', '2026-12-31'));
    $r = ProRataTemporis::dividi(100001, $zonaE, '2026-05-01');

    expect($r['giorni_periodo'])->toBe(183)->and($r['giorni_uscente'])->toBe(105)->and($r['giorni_entrante'])->toBe(78)
        ->and($r['uscente'])->toBe(57378)->and($r['entrante'])->toBe(42623);
});

it('straordinario: la competenza è il giorno della delibera — delibera prima del rogito → tutto all\'uscente, il giorno stesso o dopo → tutto all\'entrante (Cass. 24654/2010), senza passare dall\'arrotondamento', function () {
    $delibera = PeriodoCompetenza::puntuale('2026-03-01');

    expect(ProRataTemporis::dividi(120000, $delibera, '2026-05-01'))->toBe(['uscente' => 120000, 'entrante' => 0, 'giorni_uscente' => 1, 'giorni_entrante' => 0, 'giorni_periodo' => 1])
        ->and(ProRataTemporis::dividi(120000, $delibera, '2026-03-01')['entrante'])->toBe(120000)
        ->and(ProRataTemporis::dividi(120000, $delibera, '2026-02-01')['entrante'])->toBe(120000);
});

it('gli estremi sono uscite anticipate: decorrenza dopo la fine del periodo → tutto all\'uscente; il primo giorno o prima → tutto all\'entrante', function () {
    $esercizio = new PeriodoCompetenza('2026-01-01', '2026-12-31');

    expect(ProRataTemporis::dividi(41200, $esercizio, '2027-01-01'))->toBe(['uscente' => 41200, 'entrante' => 0, 'giorni_uscente' => 365, 'giorni_entrante' => 0, 'giorni_periodo' => 365])
        ->and(ProRataTemporis::dividi(41200, $esercizio, '2026-01-01')['entrante'])->toBe(41200)
        ->and(ProRataTemporis::dividi(41200, $esercizio, '2025-06-30')['entrante'])->toBe(41200)
        ->and(ProRataTemporis::dividi(41200, $esercizio, '2026-12-31'))->toBe(['uscente' => 41087, 'entrante' => 113, 'giorni_uscente' => 364, 'giorni_entrante' => 1, 'giorni_periodo' => 365]);
    // 41200 × 1/365 = 112,88 → 112; 41200 × 364/365 = 41087,12 → 41087; resta 1 → .88 vince → 113.
});

it('il segno si conserva: una quota a credito (netting) dà un conguaglio a segno rovesciato, e la somma resta l\'importo', function () {
    $r = ProRataTemporis::dividi(-41200, new PeriodoCompetenza('2026-01-01', '2026-12-31'), '2026-05-01');

    expect($r['uscente'] + $r['entrante'])->toBe(-41200)
        ->and($r['uscente'])->toBeLessThan(0)->and($r['entrante'])->toBeLessThan(0);
});

it('accetta la decorrenza come Carbon o come stringa, e la somma delle due parti è sempre l\'importo', function () {
    $esercizio = new PeriodoCompetenza('2026-01-01', '2026-12-31');
    foreach ([1, 99, 100001, 1234567] as $importo) {
        foreach (['2026-01-02', '2026-05-01', '2026-12-31'] as $d) {
            $r = ProRataTemporis::dividi($importo, $esercizio, \Carbon\CarbonImmutable::parse($d));
            expect($r['uscente'] + $r['entrante'])->toBe($importo);
        }
    }
});
