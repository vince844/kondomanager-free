<?php

use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Slice 1 — Oracolo dello Stato Patrimoniale (quadratura A = P + N).
 *
 * Questo test DOCUMENTA il "buco A": il saldo_iniziale di una cassa è una COLONNA sulla
 * tabella `casse`, non una scrittura in partita doppia. Entra quindi come pura attività
 * senza alcuna contropartita nel libro giornale → lo Stato Patrimoniale non quadra
 * (Σ Attività ≠ Σ Passività + Netto), e nessun controllo esistente lo intercetta
 * (DoubleEntryValidator verifica solo dare = avere della singola scrittura).
 *
 * Riferimenti (beta.24): CreateCassaModelAction.php:12 (saldo_iniziale = colonna),
 * SaldoCassaService.php:32 (saldo = colonna + ledger), DoubleEntryValidator.php:19.
 * Design: docs/fondo_accantonato_e_quadratura_sp.md.
 *
 * Quando la scrittura di apertura sarà in piedi (slice 1, passo successivo), lo scarto
 * misurato qui dovrà diventare 0 e l'asserzione va aggiornata a toBe(0).
 */

/** Crea una cassa Banca "migrata" con un saldo_iniziale (centesimi), come CreateCassaAccountAction in produzione. */
function creaBancaMigrata(int $condominioId, int $saldoInizialeCents): Cassa
{
    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'ruolo'         => 'conto_bancario',
        'codice'        => '1010.'.uniqid(),
        'nome'          => 'Banca Migrata '.uniqid(),
        'tipo'          => 'attivo',
        'categoria'     => 'liquidita',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return Cassa::create([
        'condominio_id'      => $condominioId,
        'nome'               => 'Banca Migrata',
        'tipo'               => 'banca',
        'conto_contabile_id' => $contoId,
        'saldo_iniziale'     => $saldoInizialeCents,
        'attiva'             => true,
    ]);
}

/** Passività + Netto ricostruibili dal SOLO libro giornale (oggi: il saldo_iniziale non compare qui). */
function passivoPiuNettoDaLedger(int $condominioId): int
{
    return (int) DB::table('righe_scritture')
        ->join('conti_contabili', 'righe_scritture.conto_contabile_id', '=', 'conti_contabili.id')
        ->where('conti_contabili.condominio_id', $condominioId)
        ->where('conti_contabili.tipo', 'passivo')
        ->selectRaw("COALESCE(SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE -importo END), 0) AS netto")
        ->value('netto');
}

test('buco A: una cassa Banca migrata con saldo_iniziale lascia lo Stato Patrimoniale sbilanciato dello stesso importo', function () {
    $condominio = Condominio::factory()->create();
    $saldoIniziale = 100_000; // €1.000,00 in centesimi — l'accantonamento 2025 già fisicamente in banca

    $banca = creaBancaMigrata($condominio->id, $saldoIniziale);

    // 1. Il libro giornale è VUOTO: il saldo_iniziale non ha generato NESSUNA scrittura di contropartita.
    expect(DB::table('scritture_contabili')->where('condominio_id', $condominio->id)->count())->toBe(0);
    expect(DB::table('righe_scritture')->count())->toBe(0);

    // 2. Eppure l'attività "banca" c'è: saldo_reale = saldo_iniziale (colonna) + ledger (0).
    expect((int) $banca->fresh()->saldo_reale)->toBe($saldoIniziale);

    // 3. Quindi lo Stato Patrimoniale NON quadra: attività reali (casse incluse) = 100.000,
    //    passività + netto ricostruibili dal ledger = 0. Lo scarto è ESATTAMENTE il
    //    saldo_iniziale mai contabilizzato. È il buco A.
    $attivoReale = (int) Cassa::where('condominio_id', $condominio->id)->get()
        ->sum(fn (Cassa $c) => (int) $c->saldo_reale);

    $sbilancio = $attivoReale - passivoPiuNettoDaLedger($condominio->id);

    // Stato PRE-migrazione: lo scarto è esattamente il saldo mai contabilizzato.
    expect($sbilancio)->toBe($saldoIniziale);

    // Dopo il backfill (beta.25) il buco si chiude: la scrittura di apertura dà
    // al saldo la sua contropartita e lo scarto va a ZERO.
    // (Servono esercizio e conto di contropartita: senza, il backfill salta in
    // sicurezza e la cassa resta nello stato pre-migrazione — vedi il test dedicato.)
    DB::table('esercizi')->insert([
        'condominio_id' => $condominio->id,
        'nome'          => 'Esercizio 2026',
        'stato'         => 'aperto',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    DB::table('conti_contabili')->insert([
        'condominio_id' => $condominio->id,
        'ruolo'         => 'passate_gestioni',
        'codice'        => '2301',
        'nome'          => 'Fondo Passate Gestioni',
        'tipo'          => 'passivo',
        'categoria'     => 'fondi',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $migration = require database_path('migrations/2026_07_24_090100_backfill_apertura_saldi_casse.php');
    $migration->up();

    $attivoDopo = (int) Cassa::where('condominio_id', $condominio->id)->get()
        ->sum(fn (Cassa $c) => (int) $c->saldo_reale);

    expect($attivoDopo - passivoPiuNettoDaLedger($condominio->id))->toBe(0);
})->group('stato-patrimoniale', 'buco-a');
