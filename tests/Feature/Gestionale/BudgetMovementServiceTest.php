<?php

use App\Services\Gestionale\BudgetMovementService;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\BudgetMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// -----------------------------------------------------------------------
// Helper: crea piano, sorgente, destinazione e un utente reale nel DB
// -----------------------------------------------------------------------
function setupPianoEConti(): array
{
    $user   = User::factory()->create();
    $piano  = PianoRate::factory()->create(['attivo' => true, 'stato' => 'bozza']);
    $source = Conto::factory()->create(['importo' => 30000]);
    $dest   = Conto::factory()->create(['importo' => 34343]);

    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $piano->id,
        'conto_id'      => $source->id,
        'importo'       => 20000,
        'note'          => null,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return [$piano, $source, $dest, $user->id];
}

// -----------------------------------------------------------------------
// CASO 1: Spostamento base — sorgente ridotta, destinazione creata
// -----------------------------------------------------------------------
it('sposta budget dalla sorgente alla destinazione (insert)', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    $service  = new BudgetMovementService();
    $movement = $service->moveBudget($piano, $source->id, $dest->id, 10000, 'Test spostamento', $userId);

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->first();
    expect($sourcePivot->importo)->toBe(10000);

    $destPivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->first();
    expect($destPivot)->not->toBeNull();
    expect($destPivot->importo)->toBe(10000);
    expect($destPivot->note)->toContain('Generato da Sposta Spesa: Test spostamento');

    expect($movement)->toBeInstanceOf(BudgetMovement::class);
    expect($movement->amount)->toBe(10000);
    expect($movement->source_conto_id)->toBe($source->id);
    expect($movement->destination_conto_id)->toBe($dest->id);
});

// -----------------------------------------------------------------------
// CASO 2: Spostamento su destinazione già esistente nel piano (update)
// -----------------------------------------------------------------------
it('sposta budget su destinazione già esistente nel piano (update)', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $piano->id,
        'conto_id'      => $dest->id,
        'importo'       => 5000,
        'note'          => null,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $service = new BudgetMovementService();
    $service->moveBudget($piano, $source->id, $dest->id, 10000, 'Integrazione', $userId);

    $destPivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->first();
    expect($destPivot->importo)->toBe(15000);
});

// -----------------------------------------------------------------------
// CASO 3: Fondi insufficienti → ValidationException + rollback
// -----------------------------------------------------------------------
it('lancia eccezione se i fondi della sorgente sono insufficienti', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    $service = new BudgetMovementService();

    expect(fn() => $service->moveBudget($piano, $source->id, $dest->id, 25000, 'Troppo', $userId))
        ->toThrow(ValidationException::class);

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->first();
    expect($sourcePivot->importo)->toBe(20000);
});

// -----------------------------------------------------------------------
// CASO 4: Sorgente e destinazione identiche → ValidationException
// -----------------------------------------------------------------------
it('lancia eccezione se sorgente e destinazione sono lo stesso conto', function () {
    [$piano, $source, , $userId] = setupPianoEConti();

    $service = new BudgetMovementService();

    expect(fn() => $service->moveBudget($piano, $source->id, $source->id, 5000, 'Auto', $userId))
        ->toThrow(ValidationException::class);
});

// -----------------------------------------------------------------------
// CASO 5: Importo zero o negativo → ValidationException
// -----------------------------------------------------------------------
it('lancia eccezione se importo è zero', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    expect(fn() => (new BudgetMovementService())->moveBudget($piano, $source->id, $dest->id, 0, 'Zero', $userId))
        ->toThrow(ValidationException::class);
});

it('lancia eccezione se importo è negativo', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    expect(fn() => (new BudgetMovementService())->moveBudget($piano, $source->id, $dest->id, -100, 'Negativo', $userId))
        ->toThrow(ValidationException::class);
});

// -----------------------------------------------------------------------
// CASO 6: Sorgente non presente nel piano → ValidationException
// -----------------------------------------------------------------------
it('lancia eccezione se la sorgente non è nel piano rate', function () {
    [$piano, , $dest, $userId] = setupPianoEConti();

    $contoEsterno = Conto::factory()->create(['importo' => 10000]);

    expect(fn() => (new BudgetMovementService())->moveBudget($piano, $contoEsterno->id, $dest->id, 5000, 'Esterno', $userId))
        ->toThrow(ValidationException::class);
});

// -----------------------------------------------------------------------
// CASO 7: Sorgente con NULL → usa preventivo del conto come disponibile
// -----------------------------------------------------------------------
it('sorgente con NULL usa il preventivo del conto come importo disponibile', function () {
    $user   = User::factory()->create();
    $piano  = PianoRate::factory()->create(['attivo' => true, 'stato' => 'bozza']);
    $source = Conto::factory()->create(['importo' => 34343]);
    $dest   = Conto::factory()->create(['importo' => 10000]);

    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $piano->id,
        'conto_id'      => $source->id,
        'importo'       => null,
        'note'          => null,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $movement = (new BudgetMovementService())->moveBudget($piano, $source->id, $dest->id, 10000, 'Da saldo', $user->id);

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->first();
    expect($sourcePivot->importo)->toBe(24343);
    expect($movement->source_old_amount)->toBe(34343);
});

// -----------------------------------------------------------------------
// CASO 8: Destinazione con NULL → preventivo + spostamento
// -----------------------------------------------------------------------
it('destinazione con NULL viene convertita in importo fisso corretto', function () {
    $user   = User::factory()->create();
    $piano  = PianoRate::factory()->create(['attivo' => true, 'stato' => 'bozza']);
    $source = Conto::factory()->create(['importo' => 30000]);
    $dest   = Conto::factory()->create(['importo' => 34343]);

    DB::table('piano_rate_capitoli')->insert([
        ['piano_rate_id' => $piano->id, 'conto_id' => $source->id, 'importo' => 30000, 'note' => null, 'created_at' => now(), 'updated_at' => now()],
        ['piano_rate_id' => $piano->id, 'conto_id' => $dest->id,   'importo' => null,  'note' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    (new BudgetMovementService())->moveBudget($piano, $source->id, $dest->id, 10000, 'Su saldo', $user->id);

    $destPivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->first();
    expect($destPivot->importo)->toBe(44343); // 34343 + 10000
});

// -----------------------------------------------------------------------
// CASO 9: Spostamento totale — svuota completamente la sorgente
// -----------------------------------------------------------------------
it('spostamento totale svuota la sorgente a zero', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    (new BudgetMovementService())->moveBudget($piano, $source->id, $dest->id, 20000, 'Svuota tutto', $userId);

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->first();
    expect($sourcePivot->importo)->toBe(0);
});

// -----------------------------------------------------------------------
// CASO 10: Rollback — errore non modifica il DB
// -----------------------------------------------------------------------
it('in caso di errore la transazione fa rollback completo', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    try {
        (new BudgetMovementService())->moveBudget($piano, $source->id, $dest->id, 99999, 'Troppo', $userId);
    } catch (ValidationException) {
        // atteso
    }

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->first();
    expect($sourcePivot->importo)->toBe(20000);

    expect(DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->exists())->toBeFalse();

    expect(DB::table('budget_movements')->count())->toBe(0);
});