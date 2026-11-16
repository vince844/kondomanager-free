<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\RegolaRecupero;
use App\Services\Gestionale\Accessori\InteressiDiMora;
use App\Services\Gestionale\Accessori\RegoleDelRecupero;
use Tests\TestCase;

/**
 * Il tasso legale scritto a mano dallo studio nel calcolo, senza database: la tabella dei tassi scritti a mano si passa al tasso di ogni giorno. Vale
 * per un anno che `config/fiscale.php` non ha; per un anno che il config ha vale il config, anche con una cifra diversa. I tratti si spezzano a ogni
 * anno anche quando il tasso è lo stesso: ogni tratto dice la sua fonte, e nessun tratto passa l'anno.
 *
 * Interessi in centesimi = capitale × tasso × giorni / divisore, con il tasso in decimillesimi di punto (1,60 % = 16.000). Ogni cifra è rifatta a mano
 * accanto al caso.
 */
uses(TestCase::class);

/** La tabella dei tassi scritti a mano, per anno, come la conservano le impostazioni. */
function itmAMano(array $tassi): array
{
    $tabella = [];
    foreach ($tassi as $anno => $tassoE4) {
        $tabella[$anno] = ['anno' => $anno, 'tasso_e4' => $tassoE4, 'fonte' => 'D.M. del dicembre prima', 'scritto_da' => 1, 'scritto_da_nome' => 'Mario Rossi',
            'scritto_il' => ($anno - 1) . '-12-16'];
    }

    return $tabella;
}

function itmChiave(string $inizio, string $scadenza): array
{
    return ['inizio' => $inizio, 'base' => true, 'ordine' => [$scadenza, 1, 1]];
}

it('il tasso di un anno che il config non ha è quello scritto a mano; per un anno che il config ha vale il config; prima della tabella non vale', function () {
    $tasso = RegoleDelRecupero::tassoAl(collect(), itmAMano([2018 => 30000, 2026 => 20000, 2027 => 22500]));

    expect($tasso('2027-03-01'))->toBe(['tasso_e4' => 22500, 'etichetta' => '2,25 %', 'fonte' => RegoleDelRecupero::FONTE_LEGALE_A_MANO, 'regola_id' => null])
        ->and(RegoleDelRecupero::FONTE_LEGALE_A_MANO)->toBe('tasso legale scritto a mano')
        ->and($tasso('2026-06-01'))->toBe(['tasso_e4' => 16000, 'etichetta' => '1,60 %', 'fonte' => 'tasso legale', 'regola_id' => null])
        ->and($tasso('2018-05-01'))->toBe('Manca il tasso legale del 2018: scrivi un tasso con la sua fonte nelle regole del recupero.')
        ->and($tasso('2028-01-01'))->toStartWith('Il tasso legale del 2028 non è ancora nel programma: puoi proporre gli interessi fino al 31/12/2027.');

    // Una regola con un tasso scritto vale anche sul tasso scritto a mano.
    $regola = new RegolaRecupero(['dal' => '2027-06-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3.0000', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento', 'giorni_decorrenza' => 0]);
    $regola->id = 4;
    $conRegola = RegoleDelRecupero::tassoAl(collect([$regola]), itmAMano([2027 => 22500]));
    expect($conRegola('2027-05-31')['tasso_e4'])->toBe(22500)->and($conRegola('2027-07-01'))->toBe(['tasso_e4' => 30000, 'etichetta' => '3,00 %', 'fonte' => 'art. 12 del regolamento', 'regola_id' => 4]);
});

it('a cavallo del 2026 (1,60 % del programma) e del 2027 (2,25 % scritto a mano): due tratti con la loro fonte, € 5,00', function () {
    // € 1.000,00 scaduti il 30/11/2026, alla data del 28/02/2027.
    // 1.000,00 × 1,60 % × 31/365 = 1,358904 (dicembre 2026) + 1.000,00 × 2,25 % × 59/365 = 3,636986 (gennaio e febbraio 2027) = 4,995890 → € 5,00.
    $esito = InteressiDiMora::calcola(['A' => itmChiave('2026-12-01', '2026-11-30')],
        [['giorno' => '2026-10-01', 'chiave' => 'A', 'importo' => 100000, 'causa' => 'emissione']],
        RegoleDelRecupero::tassoAl(collect(), itmAMano([2027 => 22500])), [], '2027-02-28');

    expect(array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['tasso_e4'], $t['fonte'], $t['divisore'], $t['esatti'], $t['causa']], $esito['A']['tratti']))->toBe([
        ['2026-12-01', '2026-12-31', 31, 16000, 'tasso legale', 365, 13589, 'scadenza'],
        ['2027-01-01', '2027-02-28', 59, 22500, 'tasso legale scritto a mano', 365, 36370, 'anno'],
    ])->and($esito['A']['interessi'])->toBe(500)->and($esito['A']['fermo'])->toBeNull();
});

it('lo stesso tasso nel 2026 e nel 2027, uno del programma e uno scritto a mano: due tratti, non uno, € 3,95', function () {
    // 1.000,00 × 1,60 % × 31/365 = 1,358904 + 1.000,00 × 1,60 % × 59/365 = 2,586301 = 3,945205 → € 3,95.
    $esito = InteressiDiMora::calcola(['A' => itmChiave('2026-12-01', '2026-11-30')],
        [['giorno' => '2026-10-01', 'chiave' => 'A', 'importo' => 100000, 'causa' => 'emissione']],
        RegoleDelRecupero::tassoAl(collect(), itmAMano([2027 => 16000])), [], '2027-02-28');

    expect(array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['fonte'], $t['esatti']], $esito['A']['tratti']))->toBe([
        ['2026-12-01', '2026-12-31', 31, 'tasso legale', 13589],
        ['2027-01-01', '2027-02-28', 59, 'tasso legale scritto a mano', 25863],
    ])->and($esito['A']['interessi'])->toBe(395);
});

it('un tasso scritto del 3,00 % per due anni interi di 365 giorni: due tratti di un anno, € 60,00, non un tratto di 730 giorni', function () {
    // € 1.000,00 scaduti il 31/12/2024, alla data del 31/12/2026: 1.000,00 × 3,00 % × 365/365 = 30,00 nel 2025 e altrettanto nel 2026 → € 60,00.
    $regola = new RegolaRecupero(['dal' => '2024-06-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3.0000', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento', 'giorni_decorrenza' => 0]);
    $regola->id = 9;
    $esito = InteressiDiMora::calcola(['A' => itmChiave('2025-01-01', '2024-12-31')],
        [['giorno' => '2024-10-01', 'chiave' => 'A', 'importo' => 100000, 'causa' => 'emissione']],
        RegoleDelRecupero::tassoAl(collect([$regola]), []), RegoleDelRecupero::cambi(collect([$regola])), '2026-12-31');

    expect(array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['tasso_e4'], $t['esatti'], $t['causa']], $esito['A']['tratti']))->toBe([
        ['2025-01-01', '2025-12-31', 365, 30000, 300000, 'scadenza'],
        ['2026-01-01', '2026-12-31', 365, 30000, 300000, 'anno'],
    ])->and($esito['A']['interessi'])->toBe(6000);
});
