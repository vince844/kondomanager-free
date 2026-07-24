<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Models\Gestionale\Cassa;
use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * La liquidità calcolata dal Treasury Guardian non deve cambiare per effetto
 * della migrazione del saldo di apertura dal campo al giornale (beta.25).
 *
 * Il caso insidioso è la vista filtrata per gestione: il saldo di apertura di una
 * cassa non appartiene ad alcuna gestione (`gestione_id` NULL), quindi un filtro
 * ingenuo lo escluderebbe e mostrerebbe MENO liquidità di quella reale — con
 * conseguenze sul predittore a 30 giorni e sugli allarmi di cassa.
 */

/** Cassa con conto ATTIVO/LIQUIDITÀ — categoria che il Treasury Guardian somma. */
function ltCreaCassaLiquidita(int $condominioId, int $saldoInizialeCents): Cassa
{
    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'ruolo'         => 'conto_bancario',
        'codice'        => '1010.'.uniqid(),
        'nome'          => 'Banca '.uniqid(),
        'tipo'          => 'attivo',
        'categoria'     => 'liquidita',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return Cassa::create([
        'condominio_id'      => $condominioId,
        'nome'               => 'Banca Liquidita',
        'tipo'               => 'banca',
        'conto_contabile_id' => $contoId,
        'saldo_iniziale'     => $saldoInizialeCents,
        'attiva'             => true,
    ]);
}

function ltLiquidita($condominio, ?int $gestioneId = null): int
{
    $svc = app(TreasuryGuardianService::class);
    $metodo = new ReflectionMethod($svc, 'calcolaLiquidita');
    $metodo->setAccessible(true);

    return $metodo->invoke($svc, $condominio->id, $gestioneId);
}

test('la liquidità non cambia dopo la migrazione dell\'apertura a giornale', function () {
    [$condominio] = setupContabile();
    ltCreaCassaLiquidita($condominio->id, 100_000);

    $prima = ltLiquidita($condominio);
    expect($prima)->toBe(100_000);

    $migration = require database_path('migrations/2026_07_24_090100_backfill_apertura_saldi_casse.php');
    $migration->up();

    expect(ltLiquidita($condominio))->toBe($prima);
})->group('treasury', 'apertura');

test('la liquidità FILTRATA PER GESTIONE include comunque il saldo di apertura', function () {
    [$condominio, , $gestione] = setupContabile();
    ltCreaCassaLiquidita($condominio->id, 100_000);

    $prima = ltLiquidita($condominio, $gestione->id);
    expect($prima)->toBe(100_000);

    $migration = require database_path('migrations/2026_07_24_090100_backfill_apertura_saldi_casse.php');
    $migration->up();

    // L'apertura ha gestione_id NULL: senza la correzione al filtro, qui la
    // liquidità crollerebbe a 0 pur essendoci 1.000 € veri in banca.
    expect(ltLiquidita($condominio, $gestione->id))->toBe($prima);
})->group('treasury', 'apertura');
