<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Gestionale\Accessori\InteressiDiMora;

/**
 * E5 della seconda prova della beta.12, nel calcolo da solo: una rata con gli interessi già registrati fino alla data della proposta (`inizio` dopo la data,
 * `inizio_pieno` entro la data) resta nella base per i crediti che la coprivano, senza interessi; prima usciva, e il suo credito copriva un'altra rata negli
 * stessi giorni. Le cifre rifatte a mano: 1,60 % l'anno, 2026 di 365 giorni.
 *
 * Che cosa NON copre: la proposta con il giornale (la prova `InteressiEreditatiCorrezioniTest.php`, E5); una rata non ancora scaduta (`inizio_pieno` dopo la
 * data), che resta fuori come prima.
 */

function imrTasso(): Closure
{
    return fn (string $giorno) => ['tasso_e4' => 16000, 'etichetta' => '1,60 %', 'fonte' => 'tasso legale'];
}

it('la rata A registrata fino al 30/06 tiene il suo credito: la rata B conta su € 300,00, non su € 200,00', function () {
    // A (31/03) e B (30/04), € 300,00 ciascuna; € 100,00 a credito dal 15/04 coprono A, la più vecchia. A è registrata fino al 30/06.
    $chiavi = [
        'A' => ['inizio' => '2026-07-01', 'inizio_pieno' => '2026-04-01', 'base' => true, 'ordine' => ['2026-03-31', 1, 1]],
        'B' => ['inizio' => '2026-05-01', 'inizio_pieno' => '2026-05-01', 'base' => true, 'ordine' => ['2026-04-30', 1, 2]],
        'anticipi' => ['inizio' => null, 'base' => false, 'ordine' => ['9999-12-31', 9, 0]],
    ];
    $movimenti = [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000, 'causa' => 'emissione'], ['giorno' => '2026-01-15', 'chiave' => 'B', 'importo' => 30000, 'causa' => 'emissione'],
        ['giorno' => '2026-04-15', 'chiave' => 'anticipi', 'importo' => -10000, 'causa' => 'versamento']];
    $esito = InteressiDiMora::calcola($chiavi, $movimenti, imrTasso(), [], '2026-06-30');
    // B: 300,00 × 1,60 % × 61/365 = 0,802192 → € 0,80. Con il codice di prima A usciva dalla base e il credito copriva B: 200,00 per 61 giorni, € 0,53.
    expect($esito['B']['interessi'])->toBe(80)->and($esito['A']['interessi'])->toBe(0)->and($esito['A']['tratti'])->toBe([]);
    // Senza `inizio_pieno` la chiave A resta fuori come prima: il cambio vale solo per una rata registrata.
    unset($chiavi['A']['inizio_pieno']);
    expect(InteressiDiMora::calcola($chiavi, $movimenti, imrTasso(), [], '2026-06-30')['B']['interessi'])->toBe(53);
});

it('il capitale letto da capitaliAl segue la stessa regola', function () {
    $chiavi = [
        'A' => ['inizio' => '2026-07-01', 'inizio_pieno' => '2026-04-01', 'base' => true, 'ordine' => ['2026-03-31', 1, 1]],
        'B' => ['inizio' => '2026-05-01', 'inizio_pieno' => '2026-05-01', 'base' => true, 'ordine' => ['2026-04-30', 1, 2]],
        'anticipi' => ['inizio' => null, 'base' => false, 'ordine' => ['9999-12-31', 9, 0]],
    ];
    $movimenti = [['giorno' => '2026-01-15', 'chiave' => 'A', 'importo' => 30000], ['giorno' => '2026-01-15', 'chiave' => 'B', 'importo' => 30000],
        ['giorno' => '2026-04-15', 'chiave' => 'anticipi', 'importo' => -10000]];
    $letto = InteressiDiMora::capitaliAl($chiavi, $movimenti, '2026-06-01', '2026-06-30');
    expect($letto['capitali'])->toBe(['A' => 20000, 'B' => 30000])->and($letto['coperti'])->toBe(['A' => 10000, 'B' => 0]);
});
