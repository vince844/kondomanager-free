<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le note della 1.12.0-beta.10, in tre lingue, dicono che cosa fa il codice.
 *
 * - **Quando cominciano gli interessi.** Dal giorno dopo la scadenza, più i giorni che il regolamento concede; ma una rata emessa dopo la scadenza
 *   conta dal giorno dopo l'emissione: prima di essere chiesta non è in ritardo (`InteressiDiMora`: il capitale di un giorno è quello alla fine del
 *   giorno prima, e il capitale nasce con l'emissione).
 * - **Il versamento dell'accordo.** Il denaro che arriva sulle rate che un accordo copre conta per l'accordo anche senza «Versamento dell'accordo»; la
 *   scelta dice dove va prima, nell'ordine proposto e su una rata coperta solo in parte (`AccordiRientro::allocati()`). Il fatto lo prova
 *   `VersamentoDellAccordoTest`; qui le parole.
 */

use App\Services\Gestionale\Accessori\InteressiDiMora;
use App\Services\Gestionale\Accessori\RegoleDelRecupero;

/** Le voci delle note della beta.10 in una lingua, unite. */
function b10Note(string $lingua): string
{
    return implode("\n", json_decode(file_get_contents(resource_path("data/changelogs/{$lingua}/1.12.0-beta.10.json")), true, 512, JSON_THROW_ON_ERROR)['features']);
}

it('una rata emessa dopo la scadenza: gli interessi contano dal giorno dopo l\'emissione; una emessa prima, dal giorno dopo la scadenza', function () {
    $tasso = RegoleDelRecupero::tassoAl(collect(), []);
    // Scade il 05/01/2026 ed è emessa il 04/10/2026: il primo giorno degli interessi è il 05/10/2026, non il 06/01/2026.
    $tardi = InteressiDiMora::calcola(['A' => ['inizio' => '2026-01-06', 'base' => true, 'ordine' => ['2026-01-05', 1, 1]]],
        [['giorno' => '2026-10-04', 'chiave' => 'A', 'importo' => 100000, 'causa' => 'emissione']], $tasso, [], '2026-11-16');
    // Emessa il 01/12/2025, prima della scadenza: dal 06/01/2026.
    $prima = InteressiDiMora::calcola(['A' => ['inizio' => '2026-01-06', 'base' => true, 'ordine' => ['2026-01-05', 1, 1]]],
        [['giorno' => '2025-12-01', 'chiave' => 'A', 'importo' => 100000, 'causa' => 'emissione']], $tasso, [], '2026-11-16');

    expect($tardi['A']['tratti'][0]['dal'])->toBe('2026-10-05')
        ->and($prima['A']['tratti'][0]['dal'])->toBe('2026-01-06');
});

it('le note dicono quando cominciano gli interessi, con l\'emissione', function (string $lingua, string $dice, string $nonDice) {
    expect(b10Note($lingua))->toContain($dice)->not->toContain($nonDice);
})->with([
    'it' => ['it', 'dal giorno dopo la scadenza, più i giorni che il regolamento concede (o dal giorno dopo l\'emissione, se la rata è emessa più tardi), al giorno che indichi',
        'dal giorno dopo la scadenza (più i giorni che il regolamento concede) al giorno che indichi'],
    'en' => ['en', 'from the day after the due date, plus the days the regulations allow (or from the day after issue, if the instalment is issued later), up to the day you indicate',
        'from the day after the due date (plus the days the regulations allow) up to the day you indicate'],
    'pt' => ['pt', 'do dia seguinte ao vencimento, mais os dias que o regulamento concede (ou do dia seguinte à emissão, se a prestação for emitida mais tarde), até ao dia que indicar',
        'do dia seguinte ao vencimento (mais os dias que o regulamento concede) até ao dia que indicar'],
]);

it('le note non dicono che solo «Versamento dell\'accordo» paga l\'accordo: il denaro sulle sue rate conta anche senza la scelta', function (string $lingua, array $dice, array $nonDice) {
    $note = b10Note($lingua);
    foreach ($dice as $frase) {
        expect($note)->toContain($frase);
    }
    foreach ($nonDice as $frase) {
        expect($note)->not->toContain($frase);
    }
})->with([
    'it' => ['it', ['la dichiarazione «Versamento dell\'accordo» lo manda prima alle rate dell\'accordo. Il denaro che arriva sulle rate dell\'accordo conta per l\'accordo anche senza la dichiarazione',
        'su una rata coperta solo in parte, senza la dichiarazione va prima alla parte fuori dall\'accordo',
        'Il denaro che i versamenti danno alle rate dell\'accordo riempie il calendario dalla rata più vecchia'],
        ['solo la dichiarazione «Versamento dell\'accordo» paga l\'accordo', 'I versamenti dichiarati «Versamento dell\'accordo» riempiono il calendario']],
    'en' => ['en', ['the declaration «Versamento dell\'accordo» (agreement payment) sends it to the agreement\'s instalments first. Money that lands on the agreement\'s instalments counts for the agreement even without the declaration',
        'on an instalment only partly covered, without the declaration it goes first to the part outside the agreement',
        'The money that payments give to the agreement\'s instalments fills the calendar from the oldest instalment'],
        ['only the declaration «Versamento dell\'accordo» (agreement payment) pays the agreement', 'Payments declared as «Versamento dell\'accordo» fill the calendar']],
    'pt' => ['pt', ['a declaração «Versamento dell\'accordo» (pagamento do acordo) envia-o primeiro para as prestações do acordo. O dinheiro que chega às prestações do acordo conta para o acordo mesmo sem a declaração',
        'numa prestação coberta só em parte, sem a declaração vai primeiro para a parte fora do acordo',
        'O dinheiro que os pagamentos dão às prestações do acordo preenche o calendário da prestação mais antiga'],
        ['só a declaração «Versamento dell\'accordo» (pagamento do acordo) paga o acordo', 'Os pagamentos declarados como «Versamento dell\'accordo» preenchem o calendário']],
]);
