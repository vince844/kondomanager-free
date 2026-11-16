<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il riquadro dei sei mesi conta i giorni dal giorno di Roma. Il condominio di `TesoreriaRifattaAManoScenario.php` (esercizio 2026: la data dei
 * sei mesi è il 31/12/2026 più sei mesi = 30/06/2027; Verdi € 600,00, Bianchi € 300,00 di rate scadute non versate).
 * Dal 16/11/2026 al 30/06/2027: 14 + 31 + 31 + 28 + 31 + 30 + 31 + 30 = 226 giorni.
 */

use App\Services\Gestionale\Debiti\SeiMesiPerAgire;

require_once __DIR__.'/../Treasury/TesoreriaRifattaAManoScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

dataset('istanti dei sei mesi', [
    'le 00:30 del 16/11/2026 (inverno)' => ['2026-11-15 23:30:00', '2026-11-16', 226, ['Verdi' => 60000, 'Bianchi' => 30000]],
    // Nel 2027 anche la rata 12 del 05/12/2026 è scaduta: Verdi € 800,00, Bianchi € 600,00, Rossi € 500,00.
    'le 00:30 del 30/06/2027 (estate): il giorno stesso' => ['2027-06-29 22:30:00', '2027-06-30', 0, ['Verdi' => 80000, 'Bianchi' => 60000, 'Rossi' => 50000]],
    'le 00:30 del 01/07/2027: passato da un giorno' => ['2027-06-30 22:30:00', '2027-07-01', -1, ['Verdi' => 80000, 'Bianchi' => 60000, 'Rossi' => 50000]],
]);

it('la data, i giorni e le persone del riquadro, nel giorno di Roma', function (string $utc, string $roma, int $giorni, array $persone) {
    $s = ldnCondominio($this, $utc);
    $r = app(SeiMesiPerAgire::class)->perCondominio($s['c']);
    $riga = $r['righe'][0];

    expect($r['oggi'])->toBe($roma)
        ->and($riga['data'])->toBe('2027-06-30')
        ->and($riga['giorni'])->toBe($giorni)
        ->and($riga['cifra'])->toBe(array_sum($persone))
        ->and(array_column($riga['persone'], 'cifra', 'nome'))->toBe($persone);
})->with('istanti dei sei mesi');

it('il compito dei sei mesi nasce a 60 giorni dalla data, nel giorno di Roma: alle 00:30 del 01/05/2027 sì, alle 00:30 del 30/04/2027 no', function () {
    // (I compiti del rendiconto, 31/12/2026 + 180 giorni = 29/06/2027, sono a parte: si contano solo quelli della riga, con i cartellini.)
    // 30/06/2027 − 60 giorni = 01/05/2027 (30 di giugno, 30 di maggio fino al 01/05 compreso). Ora legale: le 00:30 di Roma sono le 22:30 UTC del giorno prima.
    $s = ldnCondominio($this, '2027-04-30 22:30:00');
    $r = app(SeiMesiPerAgire::class)->perCondominio($s['c']);
    expect($r['oggi'])->toBe('2027-05-01')->and($r['righe'][0]['giorni'])->toBe(60)->and($r['righe'][0]['compito'])->toBeTrue()->and(array_values(array_filter($r['compiti'], fn ($c) => isset($c['cartellini']))))->toHaveCount(1);

    $this->travelTo('2027-04-29 22:30:00');
    $r = app(SeiMesiPerAgire::class)->perCondominio($s['c']);
    expect($r['oggi'])->toBe('2027-04-30')->and($r['righe'][0]['giorni'])->toBe(61)->and($r['righe'][0]['compito'])->toBeFalse()->and(array_values(array_filter($r['compiti'], fn ($c) => isset($c['cartellini']))))->toHaveCount(0);
});
