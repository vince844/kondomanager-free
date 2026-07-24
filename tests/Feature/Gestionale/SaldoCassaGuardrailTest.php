<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\SaldoCassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * GUARDRAIL — rete di sicurezza PRIMA del refactor "saldo_iniziale colonna → scrittura di apertura".
 *
 * Fissa il comportamento ATTUALE del saldo cassa in tutti e tre i code path che oggi
 * condividono la formula ibrida `saldo = saldo_iniziale (colonna) + Σdare − Σavere`:
 *   - SaldoCassaService::saldoDisponibile (SaldoCassaService.php:31)
 *   - SaldoCassaService::saldiPerCondominio (SaldoCassaService.php:83)
 *   - Cassa::getSaldoRealeAttribute (accessor saldo_reale)
 *
 * Dopo il refactor (backfill delle scritture di apertura + flip a lettura ledger-only),
 * questi test DEVONO restare identici: il saldo mostrato non cambia VALORE, cambia solo
 * FONTE (colonna → ledger). Se un saldo si sposta anche di un centesimo, il refactor ha
 * rotto qualcosa. Vedi docs/fondo_accantonato_e_quadratura_sp.md.
 */

/** Cassa + conto ATTIVO/LIQUIDITÀ, come CreateCassaAccountAction in produzione (nome univoco: no collisioni con altri file). */
function grCreaCassa(int $condominioId, string $tipo, int $saldoInizialeCents): Cassa
{
    $ruolo = match ($tipo) {
        'banca'   => 'conto_bancario',
        'fondo'   => 'fondo_riserva',
        default   => 'cassa_contanti',
    };

    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'ruolo'         => $ruolo,
        'codice'        => '1010.'.uniqid(),
        'nome'          => 'Conto '.$tipo.' '.uniqid(),
        'tipo'          => 'attivo',
        'categoria'     => 'liquidita',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return Cassa::create([
        'condominio_id'      => $condominioId,
        'nome'              => ucfirst($tipo).' '.uniqid(),
        'tipo'              => $tipo,
        'conto_contabile_id' => $contoId,
        'saldo_iniziale'    => $saldoInizialeCents,
        'attiva'            => true,
        'sottotipo_fondo'   => $tipo === 'fondo' ? 'generico' : null,
    ]);
}

/** Registra un movimento (riga DARE o AVERE) sul conto della cassa. */
function grMovimento($condominio, $esercizio, $gestione, Cassa $cassa, string $tipoRiga, int $importoCents): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id'      => $condominio->id,
        'gestione_id'        => $gestione->id,
        'esercizio_id'       => $esercizio->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza'    => now()->format('Y-m-d'),
        'numero_protocollo'  => 'GR-'.uniqid(),
        'causale'            => 'Movimento guardrail',
        'tipo_movimento'     => 'rettifica',
        'stato'              => 'registrata',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    DB::table('righe_scritture')->insert([
        'scrittura_id'       => $scritturaId,
        'conto_contabile_id' => $cassa->conto_contabile_id,
        'cassa_id'           => $cassa->id,
        'tipo_riga'          => $tipoRiga,
        'importo'            => $importoCents,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
}

test('guardrail: saldo cassa = saldo_iniziale senza movimenti (le due letture concordano)', function () {
    [$condominio] = setupContabile();
    $cassa = grCreaCassa($condominio->id, 'banca', 100_000); // €1.000,00

    $svc = app(SaldoCassaService::class);
    expect($svc->saldoDisponibile($cassa))->toBe(100_000);
    expect((int) $cassa->fresh()->saldo_reale)->toBe(100_000);
})->group('guardrail-saldo-cassa');

test('guardrail: saldo cassa = saldo_iniziale + Σdare − Σavere', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();
    $cassa = grCreaCassa($condominio->id, 'banca', 100_000);

    grMovimento($condominio, $esercizio, $gestione, $cassa, 'dare', 50_000);
    grMovimento($condominio, $esercizio, $gestione, $cassa, 'avere', 20_000);

    $svc = app(SaldoCassaService::class);
    expect($svc->saldoDisponibile($cassa))->toBe(130_000);          // 100k + 50k − 20k
    expect((int) $cassa->fresh()->saldo_reale)->toBe(130_000);
})->group('guardrail-saldo-cassa');

test('guardrail: saldiPerCondominio (batch) concorda con saldoDisponibile (singolo)', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();
    $cassa = grCreaCassa($condominio->id, 'banca', 100_000);
    grMovimento($condominio, $esercizio, $gestione, $cassa, 'dare', 50_000);

    $svc = app(SaldoCassaService::class);
    $batch = $svc->saldiPerCondominio($condominio)->firstWhere('id', $cassa->id);

    expect($batch['saldo_cents'])->toBe($svc->saldoDisponibile($cassa));
    expect($batch['saldo_cents'])->toBe(150_000);
})->group('guardrail-saldo-cassa');

test('le scritture annullate (soft-deleted) sono escluse dal saldo, da QUALUNQUE lettura', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();
    $cassa = grCreaCassa($condominio->id, 'banca', 100_000);

    grMovimento($condominio, $esercizio, $gestione, $cassa, 'dare', 50_000);

    // Annulla (soft-delete) la scrittura appena creata.
    DB::table('scritture_contabili')
        ->where('condominio_id', $condominio->id)
        ->update(['deleted_at' => now()]);

    $svc = app(SaldoCassaService::class);

    // Regressione beta.25: prima l'accessor `saldo_reale` NON filtrava le annullate
    // (rispondeva 150.000) mentre il service le filtrava (100.000) — due numeri diversi
    // per la stessa cassa. Ora l'accessor delega al service: una sola verità.
    expect($svc->saldoDisponibile($cassa))->toBe(100_000);
    expect((int) $cassa->fresh()->saldo_reale)->toBe(100_000);
    expect($svc->saldiPerCondominio($condominio)->firstWhere('id', $cassa->id)['saldo_cents'])->toBe(100_000);
})->group('guardrail-saldo-cassa', 'soft-delete');

test('guardrail: casse isolate — un movimento su una non tocca l\'altra', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();
    $banca    = grCreaCassa($condominio->id, 'banca', 100_000);
    $contanti = grCreaCassa($condominio->id, 'contanti', 30_000);

    grMovimento($condominio, $esercizio, $gestione, $banca, 'dare', 10_000);

    $svc = app(SaldoCassaService::class);
    expect($svc->saldoDisponibile($banca))->toBe(110_000);
    expect($svc->saldoDisponibile($contanti))->toBe(30_000);        // invariata
})->group('guardrail-saldo-cassa');
