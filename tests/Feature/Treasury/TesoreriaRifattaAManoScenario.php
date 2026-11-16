<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La tesoreria rifatta a mano: un condominio costruito con le rotte (emissione e incassi) e con le registrazioni scritte come le scrivono le azioni. Le
 * attese sono nei commenti dei test che lo usano, calcolate a mano.
 *
 * Il condominio: tre persone (Rossi 500, Bianchi 300, Verdi 200 millesimi), un preventivo di € 12.000,00 in 12 rate mensili dal 05/01/2026 → a rata € 500,00,
 * € 300,00, € 200,00. Tutte emesse il 02/01/2026. Incassi: Rossi le rate 1–11 (€ 5.500,00), Bianchi 1–10 (€ 3.000,00), Verdi 1–8 (€ 1.600,00).
 * Oggi il 16/11/2026. Scadute e non pagate: Bianchi la 11 (€ 300,00), Verdi la 9, 10, 11 (€ 600,00): € 900,00. In scadenza nei 30 giorni (fino al 16/12): la
 * rata 12 del 05/12, € 1.000,00.
 * Un fondo vincolato ai lavori riceve € 2.000,00 dalla banca il 01/03/2026.
 * Fatture: € 3.000,00 scaduta il 10/11 (non pregressa: entra oggi), € 7.000,00 il 20/11, € 500,00 il 20/12 (fuori finestra), € 400,00 pregressa scaduta (a
 * parte), € 900,00 contestata il 18/11 (fuori).
 */

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/LetturaDebitiProva.php';
require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/TesoreriaScenario.php';


function ldnFattura(array $s, string $numero, int $importo, string $scadenza, array $altro = []): int
{
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Fornitore '.$numero, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('fatture_passive')->insertGetId($altro + ['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore,
        'tipo_documento' => 'fattura', 'numero_documento' => $numero, 'netto_a_pagare' => $importo, 'totale_documento' => $importo, 'importo_imponibile' => $importo,
        'importo_iva' => 0, 'data_scadenza' => $scadenza, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'is_pregresso' => false,
        'data_documento' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()]);
}

function ldnCondominio($test, string $oggi = '2026-11-16 10:00:00'): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 500], 'B' => ['bianchi', 300], 'C' => ['verdi', 200]], 12, 'prima_rata', [], 1200000);
    lcdEmetti($test, $s, range(1, 12), '2026-01-02');
    $test->travelTo('2026-10-20 10:00:00');
    foreach (['rossi' => 11, 'bianchi' => 10, 'verdi' => 8] as $chi => $fino) {
        $righe = [];
        foreach (range(1, $fino) as $n) {
            $righe[lcdQuota($s, $chi, $n)] = (int) DB::table('rate_quote')->where('id', lcdQuota($s, $chi, $n))->value('importo');
        }
        lcdIncassa($test, $s, $chi, $righe, '2026-10-15');
    }
    $s['passate'] = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->value('id');
    $s['fondo'] = tsrCassa($s['c']->id, 'fondo', 'Fondo facciata', '1300');
    tsrTipo($s['fondo'], 'vincolato_lavori', 'facciata');
    tsrScrittura($s, '2026-03-01', 'giroconto', $s['fondo']->conto_contabile_id, $s['cassa']->conto_contabile_id, 200000);
    ldnFattura($s, 'SCAD', 300000, '2026-11-10');
    ldnFattura($s, 'NOV20', 700000, '2026-11-20');
    ldnFattura($s, 'DIC20', 50000, '2026-12-20');
    ldnFattura($s, 'PREG', 40000, '2025-06-30', ['is_pregresso' => true]);
    ldnFattura($s, 'CONT', 90000, '2026-11-18', ['stato_approvazione' => 'contestata']);
    $test->travelTo($oggi);

    return $s;
}

function ldnGiorno(array $t, string $giorno): array
{
    return collect($t['saldoPrevisto']['giorni'])->firstWhere('giorno', $giorno);
}
