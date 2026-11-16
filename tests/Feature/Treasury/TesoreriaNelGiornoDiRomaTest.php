<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il giorno della tesoreria è quello di Roma, alle 00:30 d'inverno e d'estate, nel giorno del cambio dell'ora, il 31/12 → 01/01 e il 29/02.
 * Una rata che scade «oggi» (Roma) è in scadenza oggi, non scaduta; una fattura che scade oggi non è scaduta; il primo giorno del saldo previsto è oggi.
 */

use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/LetturaDebitiProva.php';
require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/**
 * Una persona sola (Rossi, 1000 millesimi), € 1.200,00 in 12 rate da € 100,00, la prima il giorno dato, emesse tutte: la rata che scade il giorno di Roma
 * `$romaOggi` è quella numero N; nessun incasso. Una fattura di € 50,00 che scade il giorno di Roma.
 */
function ldgCondominio($test, string $primaScadenza, int $giorno, string $romaOggi): array
{
    $test->travelTo(substr($primaScadenza, 0, 4).'-01-01 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 1000]], 12, 'prima_rata', [], 120000, $primaScadenza, $giorno);
    lcdEmetti($test, $s, range(1, 12), substr($primaScadenza, 0, 4).'-01-01');
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Luce srl', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('fatture_passive')->insert(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore, 'tipo_documento' => 'fattura',
        'numero_documento' => '1', 'netto_a_pagare' => 5000, 'totale_documento' => 5000, 'importo_imponibile' => 5000, 'importo_iva' => 0, 'data_scadenza' => $romaOggi,
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'is_pregresso' => false, 'data_documento' => substr($primaScadenza, 0, 4).'-01-01',
        'created_at' => now(), 'updated_at' => now()]);

    return $s;
}

dataset('mezzanotti di Roma', [
    // [istante UTC, il giorno di Roma, la prima scadenza delle rate, il giorno del mese]
    'inverno, 00:30 del 16/11/2026' => ['2026-11-15 23:30:00', '2026-11-16', '2026-01-16', 16],
    'estate, 00:30 del 01/07/2026' => ['2026-06-30 22:30:00', '2026-07-01', '2026-01-01', 1],
    'cambio dell\'ora, 00:30 del 25/10/2026 (ancora ora legale)' => ['2026-10-24 22:30:00', '2026-10-25', '2026-01-25', 25],
    'cambio dell\'ora, 00:30 del 26/10/2026 (ora solare)' => ['2026-10-25 23:30:00', '2026-10-26', '2026-01-26', 26],
    'capodanno, 00:30 del 01/01/2027' => ['2026-12-31 23:30:00', '2027-01-01', '2026-02-01', 1],
    'bisestile, 00:30 del 29/02/2028' => ['2028-02-28 23:30:00', '2028-02-29', '2028-01-29', 29],
]);

it('alle 00:30 di Roma il saldo previsto parte da oggi, la rata di oggi è in scadenza (non scaduta) e la fattura di oggi non è scaduta', function (string $utc, string $roma, string $prima, int $giorno) {
    $s = ldgCondominio($this, $prima, $giorno, $roma);
    $this->travelTo($utc);
    $t = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();

    // La rata di oggi: in scadenza, € 100,00 al giorno 0; le rate prima di oggi scadute (un numero intero di rate da € 100,00).
    $prima = \Carbon\CarbonImmutable::parse($prima);
    $scaduteAtteso = 0;
    $inScadenzaOggi = 0;
    foreach (range(0, 11) as $i) {
        // Le scadenze come le scrive il piano: il giorno `$giorno` di ogni mese, dal mese della prima (fine mese se il mese è più corto).
        $d = $prima->startOfMonth()->addMonthsNoOverflow($i);
        $d = $d->setDay(min($giorno, $d->daysInMonth))->format('Y-m-d');
        if ($d < $roma) {
            $scaduteAtteso += 10000;
        } elseif ($d === $roma) {
            $inScadenzaOggi += 10000;
        }
    }
    expect($t['oggi'] ?? $t['saldoPrevisto']['giorni'][0]['giorno'])->toBe($roma)
        ->and($t['saldoPrevisto']['giorni'][0]['giorno'])->toBe($roma)
        ->and($t['saldoPrevisto']['rateScaduteCents'])->toBe($scaduteAtteso)
        ->and($t['saldoPrevisto']['giorni'][0]['rate_in_scadenza'])->toBe($inScadenzaOggi)
        ->and($inScadenzaOggi)->toBe(10000)
        ->and($t['fattureInScadenza'][0]['dataScadenza'])->toBe($roma)
        ->and($t['fattureInScadenza'][0]['scaduta'])->toBeFalse()
        ->and(count($t['saldoPrevisto']['giorni']))->toBe(31);
})->with('mezzanotti di Roma');
