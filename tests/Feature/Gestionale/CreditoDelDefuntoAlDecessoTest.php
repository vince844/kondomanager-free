<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * D1 della prova completa della Coda 663 (decisione 493, in fondo): con la successione, l'arretrato agli eredi e «si fermano al decesso», un credito del
 * defunto che il giorno prima del decesso copriva le rate dell'unità della successione resta dov'era, e non riduce gli interessi delle sue altre unità.
 *
 * Il caso: Ettore Fabbri ha la rata 1 di U1 (€ 300,00, scaduta il 30/04/2026) e una sua rata su U2 (€ 200,00, la rata 2, scaduta il 30/06, emessa il 15/01), dove
 * è proprietario anche lui; il 01/06 versa € 100,00 senza una rata, su U1: il credito copre la rata più vecchia, quella di U1. Muore il 17/11, e la
 * successione di U1 passa a Elena e Marco. Al 31/12 la rata di U2, dal 01/07 al 31/12, 184 giorni su € 200,00 al 2 %: € 2,02. Con il codice di prima dal
 * 17/11 il credito passava sulla rata di U2: 139 giorni su € 200,00 e 45 su € 100,00, € 1,77.
 *
 * Cosa NON copre: un credito su un'altra unità che copriva le rate della successione (anche lui resta dov'era, è la stessa regola); un credito nato dopo il
 * decesso sulle unità della successione (fuori dal calcolo del defunto: la stessa regola, provata solo per il piano che assorbe l'arretrato, nella prova del
 * debito ereditato); l'arretrato a nome del defunto, dove la regola non vale (l'arretrato resta suo, con il credito).
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Ettore anche proprietario di U2, con la sua rata 2 su U2 emessa il 15/01, e il versamento di € 100,00 senza rata su U1 il 01/06. */
function cddCaso($test): array
{
    $s = ideCaso($test);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario',
        'quota' => 0, 'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    ideRigaNelGiornale($s, 'Neri', lcdRata($s, 2), '2026-01-15', 20000, 'emissione_rata', 'U2');
    ideRigaNelGiornale($s, 'Neri', null, '2026-06-01', -10000, 'incasso_rata', 'U1');

    return $s;
}

it('prima della successione il credito copre la rata di U1, e la rata di U2 conta tutti i giorni: € 2,02', function () {
    // La guardia del caso: verde anche con il codice di prima.
    $s = cddCaso($this);
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    $u2 = collect(accDi($p, $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 2));
    expect([$u2['interessi'], $u2['dal'], $u2['al']])->toBe([202, '2026-07-01', '2026-12-31']);
});

it('D1: dopo la successione di U1 il credito resta sulla rata di U1, e la rata di U2 conta come prima: € 2,02', function () {
    $s = cddCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    $u1 = collect(accDi($p, $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 1));
    $u2 = collect(accDi($p, $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 2));
    // Con il codice di prima: [177, …] (dal 17/11 il credito di € 100,00 copriva la rata di U2).
    expect([$u2['interessi'], $u2['al']])->toBe([202, '2026-12-31'])
        ->and(count($u2['tratti']))->toBe(1)
        // La rata di U1: € 300,00 dal 01/05 al 01/06, poi € 200,00 fino al 16/11.
        ->and([$u1['interessi'], $u1['al']])->toBe([(int) round((30000 * 0.02 * 32 + 20000 * 0.02 * 168) / 365), '2026-11-16']);
    // E gli eredi ereditano la rata di U1 al netto del credito che la copriva: € 100,00 a testa.
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    expect($r['ereditata']['parte'])->toBe(10000);
});

it('con l\'arretrato a nome del defunto la regola non vale: il credito segue il calcolo, come prima', function () {
    // Guardia: verde anche con il codice di prima.
    $s = cddCaso($this);
    ideRegistra($this, $s, ideSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::INTERESSI_AL_DECESSO));
    $u2 = collect(accDi(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 2));
    expect($u2['interessi'])->toBe(177);
});
