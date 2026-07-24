<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * L'INVARIANTE: Attività = Passività + Patrimonio Netto.
 *
 * È il controllo che il DoubleEntryValidator non può fare: quest'ultimo verifica
 * che ogni singola scrittura abbia DARE = AVERE, ma un valore entrato nel sistema
 * SENZA contropartita non viola alcuna scrittura — semplicemente non ne ha una.
 * Solo A = P + N lo intercetta.
 */

function spCreaCassa(int $condominioId, int $saldoInizialeCents): Cassa
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
        'nome'               => 'Banca SP',
        'tipo'               => 'banca',
        'conto_contabile_id' => $contoId,
        'saldo_iniziale'     => $saldoInizialeCents,
        'attiva'             => true,
    ]);
}

function spBackfill(): void
{
    $migration = require database_path('migrations/2026_07_24_090100_backfill_apertura_saldi_casse.php');
    $migration->up();
}

test('BUCO A: una cassa migrata senza scrittura di apertura sbilancia lo Stato Patrimoniale', function () {
    [$condominio] = setupContabile();
    spCreaCassa($condominio->id, 100_000);

    $sp = app(StatoPatrimonialeService::class)->calcola($condominio);

    // €1.000 di liquidità reale senza alcuna contropartita a giornale.
    expect($sp['liquidita_non_contabilizzata'])->toBe(100_000);
    expect($sp['sbilancio'])->toBe(100_000);
    expect($sp['quadra'])->toBeFalse();
})->group('stato-patrimoniale', 'buco-a');

test('BUCO A CHIUSO: dopo il backfill lo Stato Patrimoniale quadra', function () {
    [$condominio] = setupContabile();
    spCreaCassa($condominio->id, 100_000);

    spBackfill();

    $sp = app(StatoPatrimonialeService::class)->calcola($condominio);

    expect($sp['liquidita_non_contabilizzata'])->toBe(0);
    expect($sp['attivo']['totale'])->toBe(100_000);   // la banca
    expect($sp['passivo']['totale'])->toBe(100_000);  // Fondo Passate Gestioni
    expect($sp['sbilancio'])->toBe(0);
    expect($sp['quadra'])->toBeTrue();
})->group('stato-patrimoniale', 'buco-a');

test('una cassa creata dalla 1.10 in poi nasce già quadrata', function () {
    [$condominio] = setupContabile();

    app(\App\Actions\Cassa\CreateCassaAction::class)->execute($condominio, [
        'nome'           => 'Banca Nuova',
        'tipo'           => 'contanti',
        'saldo_iniziale' => '2.500,00',
    ]);

    $sp = app(StatoPatrimonialeService::class)->calcola($condominio);

    expect($sp['attivo']['totale'])->toBe(250_000);
    expect($sp['quadra'])->toBeTrue();
})->group('stato-patrimoniale');

test('un condominio senza movimenti quadra (0 = 0)', function () {
    [$condominio] = setupContabile();

    expect(app(StatoPatrimonialeService::class)->quadra($condominio))->toBeTrue();
})->group('stato-patrimoniale');

test('il giroconto banca→fondo non altera la quadratura (riclassificazione interna)', function () {
    [$condominio] = setupContabile();
    $banca = spCreaCassa($condominio->id, 100_000);
    spBackfill();

    // Fondo di destinazione.
    $contoFondoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'ruolo'         => 'fondo_riserva',
        'codice'        => '1010.'.uniqid(),
        'nome'          => 'Fondo Lavori',
        'tipo'          => 'attivo',
        'categoria'     => 'liquidita',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    $fondo = Cassa::create([
        'condominio_id'      => $condominio->id,
        'nome'               => 'Fondo Lavori',
        'tipo'               => 'fondo',
        'conto_contabile_id' => $contoFondoId,
        'saldo_iniziale'     => 0,
        'attiva'             => true,
        'sottotipo_fondo'    => 'generico',
    ]);

    $esercizio = DB::table('esercizi')->where('condominio_id', $condominio->id)->first();
    $gestione  = DB::table('gestioni')->where('condominio_id', $condominio->id)->first();

    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id'      => $condominio->id,
        'gestione_id'        => $gestione->id,
        'esercizio_id'       => $esercizio->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza'    => now()->format('Y-m-d'),
        'numero_protocollo'  => 'GIR-'.uniqid(),
        'causale'            => 'Accantonamento',
        'tipo_movimento'     => 'giroconto',
        'stato'              => 'registrata',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $scritturaId, 'conto_contabile_id' => $contoFondoId, 'cassa_id' => $fondo->id,
         'tipo_riga' => 'dare', 'importo' => 30_000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $scritturaId, 'conto_contabile_id' => $banca->conto_contabile_id, 'cassa_id' => $banca->id,
         'tipo_riga' => 'avere', 'importo' => 30_000, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $sp = app(StatoPatrimonialeService::class)->calcola($condominio);

    // La liquidità si è solo spostata fra due partizioni dell'attivo.
    expect($sp['attivo']['totale'])->toBe(100_000);
    expect($sp['quadra'])->toBeTrue();
})->group('stato-patrimoniale');
