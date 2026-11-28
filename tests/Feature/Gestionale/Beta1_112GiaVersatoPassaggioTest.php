<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 438 e Coda 160 — il già versato e un passaggio di proprietà sull'unità dopo la generazione del piano.
 *
 * Il conguaglio del passaggio legge il già versato dalle righe che il piano ha congelato generandosi; un piano generato dopo il passaggio toglie il già versato
 * che vale nel suo esercizio (decisione 183.1), prima la parte dell'unità e poi quella della persona, come il conguaglio: le due cifre coincidono, e il
 * pannello non le confronta.
 *
 * Lo scenario è quello della Coda 160 (`Beta51PassaggiFrasiTest`): Ugo proprietario per intero, € 300,00 versati da Ugo e € 200,00 dall'unità su «Spese
 * generali» (€ 1.200,00), la vendita a Elsa dall'1/5/2026. Il conto a mano è in testa a quel file: Elsa € 671,23, Ugo € 28,77.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

/** Lo scenario della Coda 160, senza il piano generato; il già versato senza esercizio, o di quello del piano. Restituisce lo scenario e la voce. */
function g112vVersato(bool $dellEsercizioDelPiano = false): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['ripartizione' => ['proprietario' => 100], 'inquilini' => []]);
    b51aPersone($s, ['Elsa']);
    $conto = Conto::where('nome', 'Spese generali')->where('piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->firstOrFail();
    foreach ([[30000, $s['persone']['Ugo']->id], [20000, null]] as [$importo, $chi]) {
        DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $conto->id,
            'immobile_id' => $s['unita']->id, 'anagrafica_id' => $chi, 'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione',
            'esercizio_id' => $dellEsercizioDelPiano ? $s['e']->id : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    return [$s, $conto];
}

/** Lo scenario con il piano del 2026 generato ed emesso prima della vendita. */
function g112vScenario($test, bool $dellEsercizioDelPiano = false): array
{
    [$s, $conto] = g112vVersato($dellEsercizioDelPiano);
    fclGenera($s);
    fclEmetti($test, $s, '2026-12-31');

    return [$s, $conto];
}

/** L'anteprima della vendita di Ugo a Elsa dall'1/5/2026. */
function g112vAnteprima($test, array $s): array
{
    return fclAnteprima($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'));
}

/** I netti per persona del piano generato dopo la vendita registrata. */
function g112vNettiDelPianoDopo($test, bool $dellEsercizioDelPiano = false): array
{
    [$s] = g112vVersato($dellEsercizioDelPiano);
    fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'));
    fclGenera($s);

    return fclNetti($s);
}

/** Il pannello non confronta il conguaglio con un piano generato dopo il passaggio. */
function g112vNessunConfronto(array $frasi): void
{
    expect(collect($frasi)->contains(fn ($t) => str_contains($t, 'differenza') || str_contains($t, 'piano generato dopo') || str_contains($t, 'vale il conguaglio')))
        ->toBeFalse(implode(' | ', $frasi));
}

it('controprova — il già versato senza esercizio: il conguaglio (Elsa € 671,23) e il piano generato dopo la vendita danno la stessa cifra, e il pannello non li confronta', function () {
    [$s] = g112vScenario($this);
    $a = g112vAnteprima($this, $s);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 67123]])
        ->and(g112vNettiDelPianoDopo($this))->toBe(['Elsa' => 67123, 'Ugo' => 2877]);
    g112vNessunConfronto($a['rate']['conguaglio']['frasi']);
});

it('Coda 438 — il già versato indicato dopo la generazione per un altro esercizio: il conguaglio resta quello delle rate emesse (Elsa € 671,23) e nessun confronto', function () {
    [$s, $conto] = g112vScenario($this);
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e27, $s['g']->id);
    // Come lo scrive la pagina del già versato con «Vale per: Esercizio 2027».
    DB::table('contributi_versati')->where('target_id', $conto->id)->update(['esercizio_id' => $e27->id]);

    // Il conguaglio divide le righe che il piano ha già scritto: lo spostamento del già versato dopo la generazione non le cambia.
    $a = g112vAnteprima($this, $s);
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 67123]]);
    g112vNessunConfronto($a['rate']['conguaglio']['frasi']);
});

it('Coda 438 — il già versato indicato per l\'esercizio del piano: il conguaglio e il piano generato dopo la vendita danno la stessa cifra (Elsa € 671,23), e nessun confronto', function () {
    [$s] = g112vScenario($this, true);
    $a = g112vAnteprima($this, $s);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 67123]])
        ->and(g112vNettiDelPianoDopo($this, true))->toBe(['Elsa' => 67123, 'Ugo' => 2877]);
    g112vNessunConfronto($a['rate']['conguaglio']['frasi']);
});
