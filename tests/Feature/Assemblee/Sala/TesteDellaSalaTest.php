<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Sala\TesteDellaSala;

/**
 * Le teste (R2): una persona è una testa anche con più unità (Cass. 25558/2020); l'unità in comproprietà indivisa una testa con il suo rappresentante
 * (art. 67 c. 2 disp. att.); il caso misto secondo la scelta del condominio («una testa», di serie, o «due teste»); l'usufrutto punto per punto (art. 67 c.
 * 6 e 7 disp. att.: l'usufruttuario sull'ordinaria, il nudo proprietario sulla straordinaria, salvo la scelta del presidente); il conduttore che non è una
 * testa della costituzione e vota al posto del proprietario sul punto dove vota (art. 10 L. 392/1978).
 *
 * Che cosa non copre: le unità e i titolari letti dal database (lo prova `FotografiaDellaSedutaTest`).
 */
function tdsTitolare(int $id, string $ruolo): array
{
    return ['anagrafica_id' => $id, 'ruolo' => $ruolo, 'quota' => 100.0];
}

it('una persona con più unità è una testa sola, con i millesimi di tutte', function () {
    $teste = TesteDellaSala::teste([3 => ['persone' => [1], 'millesimi' => 6000000], 21 => ['persone' => [1], 'millesimi' => 500000],
        7 => ['persone' => [2], 'millesimi' => 7500000]], TesteDellaSala::UNA_TESTA, [1 => 'Rossi Mario', 2 => 'Gallo Enzo']);
    expect(array_column($teste, 'chiave'))->toBe(['p-2', 'p-1'])
        ->and($teste[1])->toMatchArray(['nome' => 'Rossi Mario', 'persone' => [1], 'unita' => [3, 21], 'millesimi' => 6500000]);
});

it('l\'unità in comproprietà indivisa è una testa, con i due comproprietari', function () {
    $teste = TesteDellaSala::teste([5 => ['persone' => [3, 2], 'millesimi' => 7000000]], TesteDellaSala::UNA_TESTA, [2 => 'Russo Anna', 3 => 'Russo Marco']);
    expect($teste)->toHaveCount(1)
        ->and($teste[0])->toMatchArray(['chiave' => 'p-2-3', 'nome' => 'Russo Anna e Russo Marco', 'persone' => [2, 3], 'unita' => [5], 'millesimi' => 7000000]);
});

it('il caso misto: con «una testa» Rossi e Bianchi sono una testa sola con l\'Int. 3 e l\'Int. 5; con «due teste» Rossi per l\'Int. 3 e l\'Int. 5 a parte', function () {
    $unita = [3 => ['persone' => [1], 'millesimi' => 6000000], 5 => ['persone' => [1, 4], 'millesimi' => 7000000]];
    $nomi = [1 => 'Rossi Mario', 4 => 'Bianchi Anna'];

    $una = TesteDellaSala::teste($unita, TesteDellaSala::UNA_TESTA, $nomi);
    expect($una)->toHaveCount(1)->and($una[0])->toMatchArray(['chiave' => 'p-1-4', 'persone' => [1, 4], 'unita' => [3, 5], 'millesimi' => 13000000]);

    $due = TesteDellaSala::teste($unita, TesteDellaSala::DUE_TESTE, $nomi);
    expect($due)->toHaveCount(2)
        ->and(collect($due)->keyBy('chiave')->map(fn ($t) => [$t['persone'], $t['unita'], $t['millesimi']])->sortKeys()->all())
        ->toBe(['p-1' => [[1], [3], 6000000], 'u-5' => [[1, 4], [5], 7000000]]);
});

it('l\'usufrutto: sull\'ordinaria vota l\'usufruttuaria, sulla straordinaria il nudo proprietario, salvo la scelta del presidente', function () {
    $titolari = [tdsTitolare(8, 'usufruttuario'), tdsTitolare(9, 'nuda_proprietario')];
    expect(TesteDellaSala::chiVota($titolari, 'ordinaria', null, null))->toBe(['persone' => [8], 'ruolo' => 'usufruttuario'])
        ->and(TesteDellaSala::chiVota($titolari, 'straordinaria', null, null))->toBe(['persone' => [9], 'ruolo' => 'nudo_proprietario'])
        ->and(TesteDellaSala::chiVota($titolari, 'straordinaria', null, 'art_1006'))->toBe(['persone' => [8], 'ruolo' => 'usufruttuario']);
});

it('l\'usufrutto nella costituzione: una testa con chi vota su almeno un punto; il nudo proprietario con soli punti ordinari no', function () {
    $titolari = [tdsTitolare(8, 'usufruttuario'), tdsTitolare(9, 'nuda_proprietario')];
    expect(TesteDellaSala::chiCostituisce($titolari, ['ordinaria', 'straordinaria']))->toBe([8, 9])
        ->and(TesteDellaSala::chiCostituisce($titolari, ['ordinaria']))->toBe([8])
        ->and(TesteDellaSala::chiCostituisce($titolari, ['straordinaria']))->toBe([9])
        ->and(TesteDellaSala::chiCostituisce($titolari, []))->toBe([8]);
});

it('il conduttore non è una testa della costituzione, e sul punto dove vota prende il posto del proprietario', function () {
    $titolari = [tdsTitolare(10, 'proprietario'), tdsTitolare(11, 'inquilino')];
    expect(TesteDellaSala::chiCostituisce($titolari, ['ordinaria', 'straordinaria']))->toBe([10])
        ->and(TesteDellaSala::chiVota($titolari, 'ordinaria', 'vota', null))->toBe(['persone' => [11], 'ruolo' => 'conduttore'])
        ->and(TesteDellaSala::chiVota($titolari, 'ordinaria', 'interviene', null))->toBe(['persone' => [10], 'ruolo' => 'proprietario'])
        ->and(TesteDellaSala::chiVota([tdsTitolare(10, 'proprietario')], 'ordinaria', 'vota', null))->toBe(['persone' => [10], 'ruolo' => 'proprietario']);
});

it('un\'unità senza titolare non è una testa', function () {
    expect(TesteDellaSala::chiVota([], 'ordinaria', null, null))->toBe(['persone' => [], 'ruolo' => 'proprietario'])
        ->and(TesteDellaSala::teste([4 => ['persone' => [], 'millesimi' => 5000000]], TesteDellaSala::UNA_TESTA, []))->toBe([]);
});
