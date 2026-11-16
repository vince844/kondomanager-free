<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La proposta degli interessi e il debito di un accordo di rientro (decisioni 389 (2) e 433). Una rata coperta resta fuori dalla base finché l'accordo non
 * è decaduto e il suo calendario non è finito, oppure è tutto versato:
 * - accordo in corso: fuori, anche la rata coperta già versata tutta;
 * - accordo rispettato fino in fondo (calendario tutto versato): fuori, anche dopo «fino al» e dopo la chiusura;
 * - calendario finito con rate non versate, senza decadenza: la rata torna, come nelle lettere e nell'incasso;
 * - decadenza registrata: la rata torna.
 *
 * Il caso di Neri (`AccordoCalendarioScenario.php`): rata 1 di € 300,00 scaduta il 31/03/2026, € 100,00 versati il 30/04, interessi registrati al 29/06
 * (€ 0,92) e € 25,00 di sollecito; accordo del 15/07/2026 su € 200,00 della rata 1 più i due addebiti, calendario 75,31 il 31/07, 75,31 il 31/08, 75,30 il
 * 30/09. Tasso legale 2026: 1,60 %. Gli interessi sulla rata 1 partono dal 30/06, il giorno dopo quelli registrati.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Le quote del caso: la rata 1, gli interessi, il sollecito. */
function isdaQuote(array $s, int $interessi, int $sollecito): array
{
    return [lcdQuota($s, 'Neri', 1), (int) DB::table('rate_quote')->where('rata_id', $interessi)->value('id'), (int) DB::table('rate_quote')->where('rata_id', $sollecito)->value('id')];
}

it('accordo in corso, la rata coperta versata tutta in anticipo: la proposta di agosto non chiede interessi su quella rata', function () {
    [$s, $interessi, $sollecito] = aclAccordo($this);
    [$q1, $qi, $qs] = isdaQuote($s, $interessi, $sollecito);
    aclVersa($this, $s, [$qi => 92, $qs => 2500, $q1 => 20000], '2026-07-31');

    $this->travelTo('2026-08-10 10:00:00');
    $neri = accDi(accProposta($this, $s, ['Neri'], '2026-08-10')->assertOk()->json(), $s, 'Neri');
    // Senza la regola: 200,00 × 1,60 % × 32/365 (dal 30/06 al 31/07) = 0,280548 → € 0,28, con l'accordo in corso.
    expect($neri['totale'] ?? 0)->toBe(0);
});

it('accordo rispettato fino all\'ultima rata: dopo «fino al», aperto o chiuso, la proposta non chiede interessi sui mesi dell\'accordo', function () {
    [$s, $interessi, $sollecito, $accordo] = aclAccordo($this);
    [$q1, $qi, $qs] = isdaQuote($s, $interessi, $sollecito);
    aclVersa($this, $s, [$qi => 92, $qs => 2500, $q1 => 4939], '2026-07-31');
    aclVersa($this, $s, [$q1 => 7531], '2026-08-31');
    aclVersa($this, $s, [$q1 => 7530], '2026-09-30');
    expect(aclRate(aclLetto($this, $s)))->toBe([[1, 7531, 0, true], [2, 7531, 0, true], [3, 7530, 0, true]]);

    // Senza la regola, al 01/10: 200,00 × 1,60 % × 32/365 = 0,280548 + 150,61 × 1,60 % × 31/365 = 0,204664 + 75,30 × 1,60 % × 30/365 = 0,099025 → € 0,58.
    $this->travelTo('2026-10-01 10:00:00');
    expect(accDi(accProposta($this, $s, ['Neri'], '2026-10-01')->assertOk()->json(), $s, 'Neri')['totale'] ?? 0)->toBe(0);

    // Chiuso dall'amministratore (non è una decadenza): resta fuori.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => $accordo->id]), ['chiuso_il' => '2026-10-01'])->assertOk();
    expect(accDi(accProposta($this, $s, ['Neri'], '2026-10-01')->assertOk()->json(), $s, 'Neri')['totale'] ?? 0)->toBe(0);
});

it('calendario finito con rate non versate, senza decadenza: dopo «fino al» la rata torna nella proposta con tutti i suoi giorni', function () {
    [$s, $interessi, $sollecito] = aclAccordo($this);
    [$q1, $qi, $qs] = isdaQuote($s, $interessi, $sollecito);
    aclVersa($this, $s, [$qi => 92, $qs => 2500, $q1 => 4939], '2026-07-31');

    // Il 30/09, ultimo giorno del calendario, l'accordo è ancora in corso: fuori.
    $this->travelTo('2026-09-30 10:00:00');
    expect(accDi(accProposta($this, $s, ['Neri'], '2026-09-30')->assertOk()->json(), $s, 'Neri')['totale'] ?? 0)->toBe(0);

    // Al 10/10: 200,00 × 1,60 % × 32/365 (dal 30/06 al 31/07) = 0,280548 + 150,61 × 1,60 % × 71/365 (dal 01/08 al 10/10) = 0,468745 = 0,749293 → € 0,75.
    $this->travelTo('2026-10-10 10:00:00');
    expect(accDi(accProposta($this, $s, ['Neri'], '2026-10-10')->assertOk()->json(), $s, 'Neri')['totale'] ?? 0)->toBe(75);
});

it('decadenza registrata prima di «fino al»: la rata torna nella proposta dal giorno della decadenza', function () {
    [$s, $interessi, $sollecito, $accordo] = aclAccordo($this);
    [$q1, $qi, $qs] = isdaQuote($s, $interessi, $sollecito);
    aclVersa($this, $s, [$qi => 92, $qs => 2500, $q1 => 4939], '2026-07-31');

    // Il 14/09 l'accordo è in corso, la rata del 31/08 non è versata: fuori.
    $this->travelTo('2026-09-14 10:00:00');
    expect(accDi(accProposta($this, $s, ['Neri'], '2026-09-14')->assertOk()->json(), $s, 'Neri')['totale'] ?? 0)->toBe(0);

    $this->travelTo('2026-09-15 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $accordo->id]),
        ['data' => '2026-09-15', 'nota' => 'Comunicata con la raccomandata del 15/09'])->assertOk();
    // Al 15/09: 200,00 × 1,60 % × 32/365 = 0,280548 + 150,61 × 1,60 % × 46/365 (dal 01/08 al 15/09) = 0,303695 = 0,584243 → € 0,58.
    expect(accDi(accProposta($this, $s, ['Neri'], '2026-09-15')->assertOk()->json(), $s, 'Neri')['totale'] ?? 0)->toBe(58);
});
