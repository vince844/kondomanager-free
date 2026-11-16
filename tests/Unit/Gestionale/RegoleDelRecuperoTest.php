<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Gestionale\Struttura\RegoleRecuperoController;
use App\Models\Gestionale\RegolaRecupero;
use App\Services\Gestionale\Accessori\RegoleDelRecupero;
use Tests\TestCase;

/**
 * Le regole del recupero lette a un giorno, senza database: il tasso legale della tabella di `config/fiscale.php` (una riga vale dal 1° gennaio del suo
 * anno fino alla riga dopo, l'ultima fino alla fine del suo anno), il tasso scritto di una regola, il primo giorno degli interessi con i giorni della
 * regola, il tasso detto per una lettera, il tasso scritto letto dal testo.
 */
uses(TestCase::class);

function rdrRegola(string $dal, array $altro = []): RegolaRecupero
{
    return new RegolaRecupero($altro + ['dal' => $dal, 'tasso_tipo' => 'legale', 'giorni_decorrenza' => 0]);
}

it('il tasso legale dell\'anno, dal 2019; prima, e dopo l\'ultimo anno scritto, non c\'è e la frase lo dice', function () {
    expect(RegoleDelRecupero::tassoLegale('2019-06-30'))->toBe(8000)
        ->and(RegoleDelRecupero::tassoLegale('2020-01-01'))->toBe(500)
        ->and(RegoleDelRecupero::tassoLegale('2021-12-31'))->toBe(100)
        ->and(RegoleDelRecupero::tassoLegale('2022-07-01'))->toBe(12500)
        ->and(RegoleDelRecupero::tassoLegale('2023-07-01'))->toBe(50000)
        ->and(RegoleDelRecupero::tassoLegale('2025-12-31'))->toBe(20000)
        ->and(RegoleDelRecupero::tassoLegale('2026-12-31'))->toBe(16000)
        ->and(RegoleDelRecupero::tassoLegale('2018-12-31'))->toBeNull()
        ->and(RegoleDelRecupero::tassoLegale('2027-01-01'))->toBeNull()
        ->and(RegoleDelRecupero::fraseTassoMancante(2018))->toBe('Manca il tasso legale del 2018: scrivi un tasso con la sua fonte nelle regole del recupero.')
        ->and(RegoleDelRecupero::fraseTassoMancante(2027))->toBe('Il tasso legale del 2027 non è ancora nel programma: puoi proporre gli interessi fino al 31/12/2026. '
            . 'Il tasso di un anno nuovo entra nel programma con un aggiornamento; intanto chi gestisce le impostazioni generali lo può scrivere a mano, con il '
            . 'decreto e la Gazzetta Ufficiale, nella tabella del tasso legale della pagina «Recupero dei crediti».');
});

it('il tasso di ogni giorno è quello della regola del giorno; senza regole il legale; i giorni della regola spostano il primo giorno', function () {
    $regole = collect([rdrRegola('2026-01-01', ['giorni_decorrenza' => 10]),
        rdrRegola('2026-07-01', ['tasso_tipo' => 'scritto', 'tasso_annuo' => '3.0000', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento'])]);
    $regole[0]->id = 7;
    $regole[1]->id = 8;
    $tasso = RegoleDelRecupero::tassoAl($regole);

    // Con la regola che vale il giorno: il tratto la conserva. Senza regole, nessuna.
    expect($tasso('2026-06-30'))->toBe(['tasso_e4' => 16000, 'etichetta' => '1,60 %', 'fonte' => 'tasso legale', 'regola_id' => 7])
        ->and($tasso('2026-07-01'))->toBe(['tasso_e4' => 30000, 'etichetta' => '3,00 %', 'fonte' => 'art. 12 del regolamento', 'regola_id' => 8])
        ->and(RegoleDelRecupero::tassoAl(collect())('2026-07-01'))->toBe(['tasso_e4' => 16000, 'etichetta' => '1,60 %', 'fonte' => 'tasso legale', 'regola_id' => null])
        ->and(RegoleDelRecupero::tassoAl(collect())('2018-05-01'))->toBe('Manca il tasso legale del 2018: scrivi un tasso con la sua fonte nelle regole del recupero.')
        // Scaduta il 31/03: il giorno dopo vale la regola del 01/01 con 10 giorni, quindi dal 11/04; scaduta il 30/06, la regola del 01/07 non ne dà.
        ->and(RegoleDelRecupero::primoGiorno($regole, '2026-03-31'))->toBe('2026-04-11')
        ->and(RegoleDelRecupero::primoGiorno($regole, '2026-06-30'))->toBe('2026-07-01')
        ->and(RegoleDelRecupero::primoGiorno(collect(), '2026-03-31'))->toBe('2026-04-01')
        ->and(RegoleDelRecupero::tassoPerLaLettera($regole, '2026-08-01'))->toBe('3,00 % annuo, art. 12 del regolamento')
        ->and(RegoleDelRecupero::tassoPerLaLettera(collect(), '2026-08-01'))->toBe('1,60 % annuo, tasso legale')
        ->and(RegoleDelRecupero::cambi($regole))->toBe(['2026-01-01', '2026-07-01']);
});

it('il tasso detto per esteso, e il tasso scritto letto dal testo come lo scrive l\'amministratore', function () {
    expect(RegoleDelRecupero::percentuale(500))->toBe('0,05 %')->and(RegoleDelRecupero::percentuale(31250))->toBe('3,125 %')->and(RegoleDelRecupero::percentuale(1000000))->toBe('100,00 %')
        ->and(RegoleRecuperoController::tasso('3'))->toBe('3.0000')
        ->and(RegoleRecuperoController::tasso('2,5'))->toBe('2.5000')
        ->and(RegoleRecuperoController::tasso('3.125 %'))->toBe('3.1250')
        ->and(RegoleRecuperoController::tasso('0'))->toBeNull()
        ->and(RegoleRecuperoController::tasso('101'))->toBeNull()
        ->and(RegoleRecuperoController::tasso('3,12345'))->toBeNull()
        ->and(RegoleRecuperoController::tasso('tre'))->toBeNull()
        ->and((new RegolaRecupero(['tasso_tipo' => 'scritto', 'tasso_annuo' => '3.1250']))->tassoE4())->toBe(31250);
});

it('l\'avviso di un tasso scritto dice la fonte che lo fa valere: il regolamento contrattuale o un voto unanime; per il regolamento, controllare che sia contrattuale', function () {
    expect(RegoleDelRecupero::AVVISO_DELIBERA)->toBe('Un tasso diverso dal legale vale se lo prevede il regolamento contrattuale o un voto unanime; una delibera a maggioranza può non bastare: controlla la fonte.')
        ->and(RegoleDelRecupero::AVVISO_REGOLAMENTO)->toBe('Un tasso diverso dal legale vale se lo prevede il regolamento contrattuale o un voto unanime: controlla che il regolamento sia contrattuale.')
        ->and(RegoleDelRecupero::avvisoDellaFonte('delibera'))->toBe(RegoleDelRecupero::AVVISO_DELIBERA)
        ->and(RegoleDelRecupero::avvisoDellaFonte('regolamento'))->toBe(RegoleDelRecupero::AVVISO_REGOLAMENTO)
        ->and(RegoleDelRecupero::avvisoDellaFonte('altro'))->toBeNull()
        ->and(RegoleDelRecupero::avvisoDellaFonte(null))->toBeNull();
    foreach ([RegoleDelRecupero::AVVISO_DELIBERA, RegoleDelRecupero::AVVISO_REGOLAMENTO] as $frase) {
        expect(mb_strtolower($frase))->not->toContain('nulla');
    }
});
