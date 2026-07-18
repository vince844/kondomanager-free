<?php

require_once __DIR__ . '/GestionaleTestHelpers.php';

/**
 * Test suite: UpdateCassaAction
 *
 * Copre due difetti corretti:
 *   1. saldo_iniziale non deve azzerarsi se assente dal payload di update.
 *   2. saldo_iniziale (e tipo) non devono essere modificabili una volta che
 *      la cassa ha movimenti contabili registrati, perché altererebbero
 *      retroattivamente saldo_reale e la verifica di capienza dei pagamenti.
 */

use App\Actions\Cassa\UpdateCassaAction;
use App\Models\Gestionale\Cassa;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function creaCassaTest(int $condominioId, array $overrides = []): Cassa
{
    $contoContabileId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'ruolo'         => null,
        'codice'        => 'CASSA-TEST-' . uniqid(),
        'nome'          => 'Conto Cassa Test',
        'tipo'          => 'attivo',
        'categoria'     => 'crediti',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return Cassa::create(array_merge([
        'condominio_id'      => $condominioId,
        'nome'               => 'Cassa Test ' . uniqid(),
        'tipo'               => 'contanti',
        'conto_contabile_id' => $contoContabileId,
        'saldo_iniziale'     => 25000, // 250,00€
        'attiva'             => true,
    ], $overrides));
}

function registraMovimentoTest(array $ctx, Cassa $cassa, int $importo = 5000): void
{
    [$condominio, $esercizio, $gestione] = $ctx;

    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id'      => $condominio->id,
        'gestione_id'        => $gestione->id,
        'esercizio_id'       => $esercizio->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza'    => now()->format('Y-m-d'),
        'numero_protocollo'  => 'TEST-' . uniqid(),
        'causale'            => 'Movimento di test',
        'tipo_movimento'     => 'rettifica',
        'stato'              => 'registrata',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    DB::table('righe_scritture')->insert([
        'scrittura_id'       => $scritturaId,
        'conto_contabile_id' => $cassa->conto_contabile_id,
        'cassa_id'           => $cassa->id,
        'tipo_riga'          => 'dare',
        'importo'            => $importo,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
}

it('non azzera il saldo iniziale se il campo è assente dal payload di update', function () {
    [$condominio] = setupContabile();
    $cassa = creaCassaTest($condominio->id, ['saldo_iniziale' => 25000]);

    $aggiornata = (new UpdateCassaAction())->execute($cassa, [
        'nome' => $cassa->nome,
        'tipo' => $cassa->tipo,
        // saldo_iniziale volutamente assente dal payload
    ]);

    expect($aggiornata->fresh()->saldo_iniziale)->toBe(25000);
});

it('applica il nuovo saldo iniziale quando presente nel payload e non ci sono movimenti', function () {
    [$condominio] = setupContabile();
    $cassa = creaCassaTest($condominio->id, ['saldo_iniziale' => 25000]);

    $aggiornata = (new UpdateCassaAction())->execute($cassa, [
        'nome'           => $cassa->nome,
        'tipo'           => $cassa->tipo,
        'saldo_iniziale' => '500,00',
    ]);

    expect($aggiornata->fresh()->saldo_iniziale)->toBe(50000);
});

it('azzera esplicitamente il saldo iniziale se il payload lo invia vuoto', function () {
    [$condominio] = setupContabile();
    $cassa = creaCassaTest($condominio->id, ['saldo_iniziale' => 25000]);

    $aggiornata = (new UpdateCassaAction())->execute($cassa, [
        'nome'           => $cassa->nome,
        'tipo'           => $cassa->tipo,
        'saldo_iniziale' => '',
    ]);

    expect($aggiornata->fresh()->saldo_iniziale)->toBe(0);
});

it('blocca la modifica del saldo iniziale se la cassa ha già movimenti contabili', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $cassa = creaCassaTest($condominio->id, ['saldo_iniziale' => 25000]);
    registraMovimentoTest($ctx, $cassa);

    expect(fn () => (new UpdateCassaAction())->execute($cassa, [
        'nome'           => $cassa->nome,
        'tipo'           => $cassa->tipo,
        'saldo_iniziale' => '999,00',
    ]))->toThrow(ValidationException::class);

    expect($cassa->fresh()->saldo_iniziale)->toBe(25000);
});

it('permette di salvare altri campi senza toccare il saldo iniziale anche con movimenti esistenti', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $cassa = creaCassaTest($condominio->id, ['saldo_iniziale' => 25000]);
    registraMovimentoTest($ctx, $cassa);

    $aggiornata = (new UpdateCassaAction())->execute($cassa, [
        'nome'           => 'Nome Aggiornato',
        'tipo'           => $cassa->tipo,
        'saldo_iniziale' => '250,00', // stesso valore: nessuna modifica reale
    ]);

    expect($aggiornata->fresh()->saldo_iniziale)->toBe(25000)
        ->and($aggiornata->fresh()->nome)->toBe('Nome Aggiornato');
});

it('blocca il cambio tipo se la cassa ha già movimenti contabili', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $cassa = creaCassaTest($condominio->id, ['tipo' => 'contanti', 'saldo_iniziale' => 0]);
    registraMovimentoTest($ctx, $cassa);

    expect(fn () => (new UpdateCassaAction())->execute($cassa, [
        'nome'           => $cassa->nome,
        'tipo'           => 'virtuale',
        'saldo_iniziale' => '0,00',
    ]))->toThrow(ValidationException::class);

    expect($cassa->fresh()->tipo)->toBe('contanti');
});
