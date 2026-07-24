<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\SaldoCassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * CONTRATTO DI RETROCOMPATIBILITÀ — aggiornamento 1.9.x → 1.10.
 *
 * Gli amministratori aggiornano il codice e lanciano `php artisan migrate` a mano,
 * quindi esiste una finestra in cui gira codice nuovo su dati non ancora migrati.
 * Per questo il saldo di apertura NON cambia formula: cambia solo DOVE vive il dato.
 *
 *   stato 1.9.x  : colonna casse.saldo_iniziale = X, nessuna scrittura di apertura
 *   stato 1.10   : colonna = 0, scrittura di apertura DARE X sul conto della cassa
 *
 * In entrambi gli stati `saldo = colonna + Σdare − Σavere` restituisce lo stesso X.
 * Questo test lo blinda: se qualcuno un giorno "ottimizza" togliendo il termine
 * colonna, le installazioni non ancora migrate mostrerebbero saldo 0 — qui diventa rosso.
 */

/** Cassa + conto ATTIVO/LIQUIDITÀ (come CreateCassaAccountAction in produzione). */
function arCreaCassa(int $condominioId, int $saldoInizialeCents): Cassa
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
        'nome'               => 'Banca Test',
        'tipo'               => 'banca',
        'conto_contabile_id' => $contoId,
        'saldo_iniziale'     => $saldoInizialeCents,
        'attiva'             => true,
    ]);
}

/**
 * Simula il risultato del backfill: crea la scrittura di apertura e azzera la colonna,
 * nella stessa transazione. `gestione_id` resta NULL — l'apertura di una cassa è un
 * fatto di condominio/esercizio, non di gestione (decisione D7 del design doc).
 */
function arMigraCassa(Cassa $cassa, $condominio, $esercizio, int $contoContropartitaId): void
{
    DB::transaction(function () use ($cassa, $condominio, $esercizio, $contoContropartitaId) {
        $importo = (int) $cassa->saldo_iniziale;

        $scritturaId = DB::table('scritture_contabili')->insertGetId([
            'condominio_id'      => $condominio->id,
            'gestione_id'        => null,
            'esercizio_id'       => $esercizio->id,
            'data_registrazione' => $esercizio->data_inizio,
            'data_competenza'    => $esercizio->data_inizio,
            'numero_protocollo'  => 'AP-'.uniqid(),
            'causale'            => 'Saldo di apertura — '.$cassa->nome,
            'tipo_movimento'     => 'apertura',
            'stato'              => 'registrata',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // DARE sulla cassa (attivo aumenta) / AVERE sulla contropartita patrimoniale.
        DB::table('righe_scritture')->insert([
            [
                'scrittura_id'       => $scritturaId,
                'conto_contabile_id' => $cassa->conto_contabile_id,
                'cassa_id'           => $cassa->id,
                'tipo_riga'          => 'dare',
                'importo'            => $importo,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'scrittura_id'       => $scritturaId,
                'conto_contabile_id' => $contoContropartitaId,
                'cassa_id'           => null,
                'tipo_riga'          => 'avere',
                'importo'            => $importo,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
        ]);

        // Il dato si sposta: non deve MAI essere contato due volte.
        DB::table('casse')->where('id', $cassa->id)->update(['saldo_iniziale' => 0]);
    });
}

test('il saldo è identico prima e dopo il backfill dell\'apertura', function () {
    [$condominio, $esercizio] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);

    $svc = app(SaldoCassaService::class);

    // STATO 1.9.x — il saldo vive in colonna.
    $saldoPrima = $svc->saldoDisponibile($cassa);
    expect($saldoPrima)->toBe(100_000);

    $contropartita = DB::table('conti_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('ruolo', 'passate_gestioni')
        ->value('id');

    arMigraCassa($cassa, $condominio, $esercizio, $contropartita);

    // STATO 1.10 — il saldo vive a giornale, la colonna è a zero.
    $cassa->refresh();
    expect((int) $cassa->saldo_iniziale)->toBe(0);
    expect($svc->saldoDisponibile($cassa))->toBe($saldoPrima);
    expect((int) $cassa->saldo_reale)->toBe($saldoPrima);
    expect($svc->saldiPerCondominio($condominio)->firstWhere('id', $cassa->id)['saldo_cents'])
        ->toBe($saldoPrima);
})->group('retrocompatibilita', 'apertura');

test('il saldo resta corretto anche con movimenti successivi all\'apertura', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);

    $contropartita = DB::table('conti_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('ruolo', 'passate_gestioni')
        ->value('id');

    arMigraCassa($cassa, $condominio, $esercizio, $contropartita);
    $cassa->refresh();

    // Un incasso da 50.000 dopo la migrazione.
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id'      => $condominio->id,
        'gestione_id'        => $gestione->id,
        'esercizio_id'       => $esercizio->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza'    => now()->format('Y-m-d'),
        'numero_protocollo'  => 'MV-'.uniqid(),
        'causale'            => 'Incasso dopo apertura',
        'tipo_movimento'     => 'incasso_rata',
        'stato'              => 'registrata',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    DB::table('righe_scritture')->insert([
        'scrittura_id'       => $scritturaId,
        'conto_contabile_id' => $cassa->conto_contabile_id,
        'cassa_id'           => $cassa->id,
        'tipo_riga'          => 'dare',
        'importo'            => 50_000,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    expect(app(SaldoCassaService::class)->saldoDisponibile($cassa))->toBe(150_000);
})->group('retrocompatibilita', 'apertura');

/** Esegue il backfill reale della migrazione (non una simulazione). */
function arEseguiBackfill(): void
{
    $migration = require database_path('migrations/2026_07_24_090100_backfill_apertura_saldi_casse.php');
    $migration->up();
}

test('BACKFILL REALE: il saldo non cambia, la colonna si azzera, il giornale quadra', function () {
    [$condominio, $esercizio] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);

    $svc = app(SaldoCassaService::class);
    $saldoPrima = $svc->saldoDisponibile($cassa);

    arEseguiBackfill();

    $cassa->refresh();
    expect((int) $cassa->saldo_iniziale)->toBe(0);
    expect($svc->saldoDisponibile($cassa))->toBe($saldoPrima);

    $scritturaId = DB::table('scritture_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('tipo_movimento', 'apertura')
        ->value('id');
    assertQuadraturaPerfetta($scritturaId);
})->group('retrocompatibilita', 'backfill');

test('BACKFILL REALE: è idempotente — eseguirlo due volte non duplica nulla', function () {
    [$condominio] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);
    $svc = app(SaldoCassaService::class);

    arEseguiBackfill();
    arEseguiBackfill(); // seconda passata

    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'apertura')->count())->toBe(1);
    expect($svc->saldoDisponibile($cassa->refresh()))->toBe(100_000);
})->group('retrocompatibilita', 'backfill');

test('BACKFILL REALE: un saldo di apertura negativo resta bilanciato e corretto', function () {
    [$condominio] = setupContabile();
    $cassa = arCreaCassa($condominio->id, -25_000); // conto scoperto
    $svc = app(SaldoCassaService::class);

    arEseguiBackfill();

    expect($svc->saldoDisponibile($cassa->refresh()))->toBe(-25_000);

    $scritturaId = DB::table('scritture_contabili')
        ->where('tipo_movimento', 'apertura')->value('id');
    assertQuadraturaPerfetta($scritturaId); // niente importi negativi a giornale
})->group('retrocompatibilita', 'backfill');

test('BACKFILL REALE: salta (senza rompere) le casse senza contropartita disponibile', function () {
    [$condominio] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);

    // Rimuove il conto di contropartita: il backfill deve saltare, non esplodere.
    DB::table('conti_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('ruolo', 'passate_gestioni')
        ->delete();

    arEseguiBackfill();

    // Cassa lasciata nello stato 1.9.x — saldo comunque corretto.
    $cassa->refresh();
    expect((int) $cassa->saldo_iniziale)->toBe(100_000);
    expect(app(SaldoCassaService::class)->saldoDisponibile($cassa))->toBe(100_000);
    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'apertura')->count())->toBe(0);
})->group('retrocompatibilita', 'backfill');

test('ROLLBACK: il down() riporta il saldo in colonna e non lascia aperture orfane', function () {
    [$condominio] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);
    $svc = app(SaldoCassaService::class);

    $migration = require database_path('migrations/2026_07_24_090100_backfill_apertura_saldi_casse.php');
    $migration->up();

    expect((int) $cassa->fresh()->saldo_iniziale)->toBe(0);

    $migration->down();

    // Saldo riportato in colonna, identico all'originale.
    expect((int) $cassa->fresh()->saldo_iniziale)->toBe(100_000);
    expect($svc->saldoDisponibile($cassa->fresh()))->toBe(100_000);

    // Nessuna apertura residua: la migrazione dello schema (che torna NOT NULL)
    // può girare subito dopo senza incontrare gestione_id NULL. È l'ordine in cui
    // Laravel esegue il rollback di un batch (inverso rispetto alla up).
    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'apertura')->count())->toBe(0);
    expect(DB::table('scritture_contabili')->whereNull('gestione_id')->count())->toBe(0);
})->group('retrocompatibilita', 'backfill');

test('GUARDIA: dopo il backfill la cassa resta modificabile (l\'apertura non è un movimento operativo)', function () {
    [$condominio] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);

    arEseguiBackfill();
    $cassa->refresh();

    // Ha una scrittura a giornale...
    expect($cassa->movimenti()->exists())->toBeTrue();
    // ...ma nessun movimento OPERATIVO: resta modificabile dall'amministratore.
    expect($cassa->hasMovimentiOperativi())->toBeFalse();

    // La modifica del tipo passa (prima di questa correzione sarebbe stata bloccata).
    app(\App\Actions\Cassa\UpdateCassaAction::class)->execute($cassa, [
        'nome' => 'Cassa Rinominata',
        'tipo' => 'contanti',
    ]);

    expect($cassa->fresh()->tipo)->toBe('contanti');
})->group('retrocompatibilita', 'guardia');

test('GUARDIA: un movimento vero blocca ancora la modifica di tipo e saldo', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);

    arEseguiBackfill();
    $cassa->refresh();

    // Un incasso reale: da qui in poi la cassa è "usata".
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id'      => $condominio->id,
        'gestione_id'        => $gestione->id,
        'esercizio_id'       => $esercizio->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza'    => now()->format('Y-m-d'),
        'numero_protocollo'  => 'MV-'.uniqid(),
        'causale'            => 'Incasso reale',
        'tipo_movimento'     => 'incasso_rata',
        'stato'              => 'registrata',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    DB::table('righe_scritture')->insert([
        'scrittura_id'       => $scritturaId,
        'conto_contabile_id' => $cassa->conto_contabile_id,
        'cassa_id'           => $cassa->id,
        'tipo_riga'          => 'dare',
        'importo'            => 5_000,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    expect($cassa->fresh()->hasMovimentiOperativi())->toBeTrue();

    app(\App\Actions\Cassa\UpdateCassaAction::class)->execute($cassa->fresh(), [
        'nome' => 'Tentativo',
        'tipo' => 'contanti',
    ]);
})->throws(\Illuminate\Validation\ValidationException::class)
  ->group('retrocompatibilita', 'guardia');

test('CASSA NUOVA: nasce già con l\'apertura a giornale e il saldo corretto', function () {
    [$condominio] = setupContabile();

    $cassa = app(\App\Actions\Cassa\CreateCassaAction::class)->execute($condominio, [
        'nome'           => 'Banca Nuova',
        'tipo'           => 'banca',
        'saldo_iniziale' => '1.500,00', // stringa formattata, come dal form
    ]);

    // Il saldo esposto è quello inserito dall'amministratore...
    expect(app(SaldoCassaService::class)->saldoDisponibile($cassa))->toBe(150_000);
    // ...ma vive a giornale, non in colonna.
    expect((int) $cassa->saldo_iniziale)->toBe(0);

    $scritturaId = DB::table('scritture_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('tipo_movimento', 'apertura')
        ->value('id');

    expect($scritturaId)->not->toBeNull();
    assertQuadraturaPerfetta($scritturaId);

    // Nessun buco patrimoniale: la contropartita esiste ed è sul passivo.
    $contropartita = DB::table('righe_scritture as rs')
        ->join('conti_contabili as cc', 'rs.conto_contabile_id', '=', 'cc.id')
        ->where('rs.scrittura_id', $scritturaId)
        ->where('cc.tipo', 'passivo')
        ->first();
    expect($contropartita)->not->toBeNull();
    expect((int) $contropartita->importo)->toBe(150_000);
})->group('apertura', 'cassa-nuova');

test('REGRESSIONE: una cassa Banca senza "intestatario" non va più in errore', function () {
    [$condominio] = setupContabile();

    // `intestatario` è nullable in validazione: lasciarlo vuoto leggeva
    // $cassa->condominio->nome su una relazione che non esisteva → errore.
    $cassa = app(\App\Actions\Cassa\CreateCassaAction::class)->execute($condominio, [
        'nome' => 'Banca Senza Intestatario',
        'tipo' => 'banca',
        'iban' => 'IT60X0542811101000000123456',
    ]);

    expect($cassa->contoCorrente)->not->toBeNull();
    expect($cassa->contoCorrente->intestatario)->toBe($condominio->nome);
})->group('regressione', 'cassa-nuova');

test('CASSA NUOVA: senza saldo di apertura non genera alcuna scrittura', function () {
    [$condominio] = setupContabile();

    $cassa = app(\App\Actions\Cassa\CreateCassaAction::class)->execute($condominio, [
        'nome' => 'Cassa Vuota',
        'tipo' => 'contanti',
    ]);

    expect(app(SaldoCassaService::class)->saldoDisponibile($cassa))->toBe(0);
    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'apertura')->count())->toBe(0);
    // Resta pienamente modificabile.
    expect($cassa->hasMovimentiOperativi())->toBeFalse();
})->group('apertura', 'cassa-nuova');

test('l\'apertura è una scrittura in partita doppia quadrata', function () {
    [$condominio, $esercizio] = setupContabile();
    $cassa = arCreaCassa($condominio->id, 100_000);

    $contropartita = DB::table('conti_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('ruolo', 'passate_gestioni')
        ->value('id');

    arMigraCassa($cassa, $condominio, $esercizio, $contropartita);

    $scritturaId = DB::table('scritture_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('tipo_movimento', 'apertura')
        ->value('id');

    expect($scritturaId)->not->toBeNull();
    assertQuadraturaPerfetta($scritturaId);

    // gestione_id NULL: l'apertura di una cassa non appartiene ad alcuna gestione (D7).
    expect(DB::table('scritture_contabili')->where('id', $scritturaId)->value('gestione_id'))
        ->toBeNull();
})->group('retrocompatibilita', 'apertura');
