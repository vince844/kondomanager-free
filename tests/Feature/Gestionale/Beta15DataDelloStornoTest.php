<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La data dello storno di una regolazione immediata e di un giroconto, con la regola dello storno di un incasso: un errore si annulla alla data
 * dell'originale nello stesso esercizio; in un esercizio diverso (l'originale è chiuso), oggi. Il caso: regolazione del 15/03/2026, esercizio 2026
 * ancora aperto, stornata il 10/01/2027: lo storno stava nel 2026 con la data del 10/01/2027, fuori dal periodo, e un riepilogo per date lo perdeva.
 */

use App\Actions\Gestionale\Movimenti\RegistraGirocontoAction;
use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Actions\Gestionale\Movimenti\StornaGirocontoAction;
use App\Actions\Gestionale\Movimenti\StornaRegolazioneImmediataAction;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/** Il condominio del 2026 con la regolazione e il giroconto del 15/03/2026; il 2027 aperto se $chiuso2026. */
function b15dsScenario($test, bool $chiuso2026): array
{
    $test->travelTo(\Carbon\Carbon::parse('2026-03-20 10:00:00'));
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione, , , $voce] = $ctx;
    $cassa = DB::table('casse')->where('condominio_id', $condominio->id)->value('id');
    $regolazione = (new RegistraRegolazioneImmediataAction())->execute([
        'gestione_id' => $gestione->id, 'esercizio_id' => $esercizio->id, 'conto_id' => $voce->id, 'cassa_id' => $cassa, 'fornitore_id' => null,
        'data_operazione' => '2026-03-15', 'causale' => 'Imposta di bollo', 'importo' => 16.00,
    ], $condominio, $esercizio);

    $mastro = fn (string $ruolo) => DB::table('conti_contabili')->insertGetId(['condominio_id' => $condominio->id, 'ruolo' => $ruolo, 'codice' => '1010.'.uniqid(),
        'nome' => 'Conto '.$ruolo, 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);
    $banca = Cassa::create(['condominio_id' => $condominio->id, 'nome' => 'Banca', 'tipo' => 'banca', 'conto_contabile_id' => $mastro('conto_bancario'),
        'saldo_iniziale' => 100000, 'attiva' => true]);
    $contanti = Cassa::create(['condominio_id' => $condominio->id, 'nome' => 'Contanti', 'tipo' => 'contanti', 'conto_contabile_id' => $mastro('cassa_contanti'),
        'saldo_iniziale' => 0, 'attiva' => true]);
    $giroconto = app(RegistraGirocontoAction::class)->execute([
        'cassa_origine_id' => $banca->id, 'cassa_destinazione_id' => $contanti->id, 'importo' => 50.00, 'data_operazione' => '2026-03-15',
        'causale' => 'Prelievo per la cassa', 'gestione_id' => $gestione->id, 'esercizio_id' => $esercizio->id,
    ], $condominio, $esercizio);

    $e27 = null;
    if ($chiuso2026) {
        $e27 = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => 'Esercizio 2027', 'stato' => 'aperto', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
        DB::table('esercizio_gestione')->insert(['esercizio_id' => $e27->id, 'gestione_id' => $gestione->id, 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);
    }
    $test->travelTo(\Carbon\Carbon::parse('2027-01-10 10:00:00'));

    return ['c' => $condominio, 'e' => $esercizio, 'e27' => $e27, 'regolazione' => $regolazione, 'giroconto' => $giroconto];
}

function b15dsData(ScritturaContabile $s): string
{
    return $s->fresh()->data_competenza->format('Y-m-d');
}

test('nello stesso esercizio, ancora aperto: lo storno della regolazione e quello del giroconto stanno alla data dell\'originale', function () {
    $s = b15dsScenario($this, chiuso2026: false);

    $stornoReg = app(StornaRegolazioneImmediataAction::class)->execute($s['regolazione']->fresh(), $s['c'], 'Bollo registrato due volte');
    $stornoGir = app(StornaGirocontoAction::class)->execute($s['giroconto']->fresh(), $s['c'], 'Prelievo mai fatto');

    expect([b15dsData($stornoReg), (int) $stornoReg->esercizio_id])->toBe(['2026-03-15', (int) $s['e']->id])
        ->and([b15dsData($stornoGir), (int) $stornoGir->esercizio_id])->toBe(['2026-03-15', (int) $s['e']->id])
        // La data della registrazione resta quella di oggi: il giornale dice quando lo storno è stato scritto.
        ->and($stornoReg->fresh()->data_registrazione->format('Y-m-d'))->toBe('2027-01-10');
});

test('con l\'esercizio dell\'originale chiuso: lo storno va nell\'esercizio aperto, con la data di oggi', function () {
    $s = b15dsScenario($this, chiuso2026: true);

    $stornoReg = app(StornaRegolazioneImmediataAction::class)->execute($s['regolazione']->fresh(), $s['c'], 'Bollo registrato due volte');
    $stornoGir = app(StornaGirocontoAction::class)->execute($s['giroconto']->fresh(), $s['c'], 'Prelievo mai fatto');

    expect([b15dsData($stornoReg), (int) $stornoReg->esercizio_id])->toBe(['2027-01-10', (int) $s['e27']->id])
        ->and([b15dsData($stornoGir), (int) $stornoGir->esercizio_id])->toBe(['2027-01-10', (int) $s['e27']->id]);
});
