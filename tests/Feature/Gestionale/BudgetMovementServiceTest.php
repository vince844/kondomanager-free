<?php

use App\Services\Gestionale\BudgetMovementService;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\BudgetMovement;
use App\Models\Esercizio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function budgetMovementService(): BudgetMovementService
{
    return new BudgetMovementService(new SpesaPerVoceService());
}

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
// Helper: registra dello "speso" reale su una voce — una riga a giornale con
// voce_spesa_id, la stessa fonte che SpesaPerVoceService legge (righe_scritture,
// non righe_fattura: quella era la fonte incompleta corretta nella beta.30).
// -----------------------------------------------------------------------
function registraSpesoSuVoce(PianoRate $piano, Conto $conto, int $centesimi): void
{
    $esercizio = Esercizio::factory()->create(['condominio_id' => $piano->condominio_id]);
    DB::table('esercizio_gestione')->insert([
        'esercizio_id' => $esercizio->id,
        'gestione_id'  => $piano->gestione_id,
        'attiva'       => true,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $contoContabileId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $piano->condominio_id,
        'codice'        => 'TEST-' . uniqid(),
        'nome'          => 'Conto di test',
        'tipo'          => 'costo',
        'categoria'     => 'costi',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id'      => $piano->condominio_id,
        'gestione_id'        => $piano->gestione_id,
        'esercizio_id'       => $esercizio->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza'    => now()->format('Y-m-d'),
        'numero_protocollo'  => 'TEST-' . uniqid(),
        'causale'            => 'Fattura di test',
        'tipo_movimento'     => 'fattura_passiva',
        'stato'              => 'registrata',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    DB::table('righe_scritture')->insert([
        'scrittura_id'        => $scritturaId,
        'conto_contabile_id'  => $contoContabileId,
        'voce_spesa_id'       => $conto->id,
        'tipo_riga'           => 'dare',
        'importo'             => $centesimi,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
}

// -----------------------------------------------------------------------
// CASO 1: Spostamento base — sorgente ridotta, destinazione creata
// -----------------------------------------------------------------------
it('sposta budget dalla sorgente alla destinazione (insert)', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    $service  = budgetMovementService();
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

    $service = budgetMovementService();
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

    $service = budgetMovementService();

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

    $service = budgetMovementService();

    expect(fn() => $service->moveBudget($piano, $source->id, $source->id, 5000, 'Auto', $userId))
        ->toThrow(ValidationException::class);
});

// -----------------------------------------------------------------------
// CASO 5: Importo zero o negativo → ValidationException
// -----------------------------------------------------------------------
it('lancia eccezione se importo è zero', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    expect(fn() => (budgetMovementService())->moveBudget($piano, $source->id, $dest->id, 0, 'Zero', $userId))
        ->toThrow(ValidationException::class);
});

it('lancia eccezione se importo è negativo', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    expect(fn() => (budgetMovementService())->moveBudget($piano, $source->id, $dest->id, -100, 'Negativo', $userId))
        ->toThrow(ValidationException::class);
});

// -----------------------------------------------------------------------
// CASO 6: Sorgente non presente nel piano → ValidationException
// -----------------------------------------------------------------------
it('lancia eccezione se la sorgente non è nel piano rate', function () {
    [$piano, , $dest, $userId] = setupPianoEConti();

    $contoEsterno = Conto::factory()->create(['importo' => 10000]);

    expect(fn() => (budgetMovementService())->moveBudget($piano, $contoEsterno->id, $dest->id, 5000, 'Esterno', $userId))
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

    $movement = (budgetMovementService())->moveBudget($piano, $source->id, $dest->id, 10000, 'Da saldo', $user->id);

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

    (budgetMovementService())->moveBudget($piano, $source->id, $dest->id, 10000, 'Su saldo', $user->id);

    $destPivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->first();
    expect($destPivot->importo)->toBe(44343); // 34343 + 10000
});

// -----------------------------------------------------------------------
// CASO 9: Spostamento totale — svuota completamente la sorgente
// -----------------------------------------------------------------------
it('spostamento totale svuota la sorgente a zero', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();

    (budgetMovementService())->moveBudget($piano, $source->id, $dest->id, 20000, 'Svuota tutto', $userId);

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
        (budgetMovementService())->moveBudget($piano, $source->id, $dest->id, 99999, 'Troppo', $userId);
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

// -----------------------------------------------------------------------
// CASO 11: Non si può spostare via budget già coperto da fatture registrate
//
// La voce sorgente ha pivot 20000, ma 15000 sono già "speso" (una riga a giornale
// con voce_spesa_id). Spostare 10000 lascerebbe solo 10000 a coprire 15000 già
// fatturati: va bloccato, anche se il pivot da solo lo permetterebbe.
// -----------------------------------------------------------------------
it('blocca lo spostamento se lascerebbe scoperte fatture già registrate', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();
    registraSpesoSuVoce($piano, $source, 15000);

    expect(fn() => budgetMovementService()->moveBudget($piano, $source->id, $dest->id, 10000, 'Troppo, c\'è già speso', $userId))
        ->toThrow(ValidationException::class);

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->first();
    expect($sourcePivot->importo)->toBe(20000);
    expect(DB::table('budget_movements')->count())->toBe(0);
});

// -----------------------------------------------------------------------
// CASO 12: Si può spostare esattamente il margine sopra lo speso, non un centesimo di più
// -----------------------------------------------------------------------
it('permette di spostare esattamente il margine sopra lo speso registrato', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();
    registraSpesoSuVoce($piano, $source, 15000); // pivot 20000 - speso 15000 = margine 5000

    budgetMovementService()->moveBudget($piano, $source->id, $dest->id, 5000, 'Esatto il margine', $userId);

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->first();
    expect($sourcePivot->importo)->toBe(15000);

    expect(fn() => budgetMovementService()->moveBudget($piano, $source->id, $dest->id, 1, 'Un centesimo di troppo', $userId))
        ->toThrow(ValidationException::class);
});

// -----------------------------------------------------------------------
// CASO 13: Una voce già interamente sforata (speso oltre il pivot) non cede mai budget
// -----------------------------------------------------------------------
it('blocca qualunque spostamento da una voce già sforata rispetto al suo pivot', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();
    registraSpesoSuVoce($piano, $source, 25000); // più dei 20000 di pivot: già in sforo

    expect(fn() => budgetMovementService()->moveBudget($piano, $source->id, $dest->id, 1, 'Anche solo un centesimo', $userId))
        ->toThrow(ValidationException::class);
});

// -----------------------------------------------------------------------
// CASO 14: Storno — crea il movimento uguale e contrario, e riporta i pivot al punto di partenza
// -----------------------------------------------------------------------
it('storna un movimento creandone uno uguale e contrario', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();
    $movimento = budgetMovementService()->moveBudget($piano, $source->id, $dest->id, 8000, 'Da stornare', $userId);

    $storno = budgetMovementService()->reverseMovement($movimento, $userId);

    expect($storno->source_conto_id)->toBe($dest->id);
    expect($storno->destination_conto_id)->toBe($source->id);
    expect($storno->amount)->toBe(8000);
    expect($storno->reverses_movement_id)->toBe($movimento->id);

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->first();
    $destPivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->first();

    // La sorgente aveva già una riga pivot reale (20000): torna esattamente lì. La destinazione
    // non aveva NESSUNA riga (non 34343 — quel fallback scatta solo su pivot NULL, non su riga
    // assente): il primo spostamento gliene ha creata una, che lo storno riporta a zero, non a
    // "nessuna riga" — il pivot, una volta creato, resta come traccia d'audit anche a saldo zero.
    expect($sourcePivot->importo)->toBe(20000);
    expect($destPivot->importo)->toBe(0);
});

// -----------------------------------------------------------------------
// CASO 15: Un movimento già stornato non si può stornare due volte
// -----------------------------------------------------------------------
it('rifiuta di stornare due volte lo stesso movimento', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();
    $movimento = budgetMovementService()->moveBudget($piano, $source->id, $dest->id, 8000, 'Da stornare', $userId);
    budgetMovementService()->reverseMovement($movimento, $userId);

    expect(fn() => budgetMovementService()->reverseMovement($movimento->fresh(), $userId))
        ->toThrow(ValidationException::class);

    expect(DB::table('budget_movements')->where('reverses_movement_id', $movimento->id)->count())->toBe(1);
});

// -----------------------------------------------------------------------
// CASO 15-bis: Non si storna uno storno — la catena avanti-indietro si ferma qui
//
// Trovato in Fase 1-bis: reverseMovement() controllava solo "qualcuno ha già stornato
// QUESTO id", non "questo id è esso stesso uno storno". Un secondo storno sullo storno
// avrebbe rifatto l'operazione originale, aggirando la garanzia dichiarata a parole.
// -----------------------------------------------------------------------
it('rifiuta di stornare uno storno', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();
    $movimento = budgetMovementService()->moveBudget($piano, $source->id, $dest->id, 8000, 'Da stornare', $userId);
    $storno = budgetMovementService()->reverseMovement($movimento, $userId);

    expect(fn() => budgetMovementService()->reverseMovement($storno->fresh(), $userId))
        ->toThrow(ValidationException::class);

    expect(DB::table('budget_movements')->where('piano_rate_id', $piano->id)->count())->toBe(2);
});

// -----------------------------------------------------------------------
// CASO 16: Lo storno rispetta a sua volta il controllo sullo speso
//
// Se nel frattempo la DESTINAZIONE originale ha già speso quanto ricevuto, restituire
// i fondi la lascerebbe scoperta — lo storno deve bloccarsi come farebbe un movimento normale.
// -----------------------------------------------------------------------
it('blocca lo storno se la destinazione originale ha già speso quanto ricevuto', function () {
    [$piano, $source, $dest, $userId] = setupPianoEConti();
    $movimento = budgetMovementService()->moveBudget($piano, $source->id, $dest->id, 8000, 'Verso il cancello', $userId);

    // La ex-destinazione ($dest) ha ora pivot 8000 (34343 + 8000... in realtà il conto NULL
    // diventa 34343+8000=42343). Registriamo speso pari a tutto il suo pivot attuale.
    $destPivotAttuale = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->value('importo');
    registraSpesoSuVoce($piano, $dest, $destPivotAttuale);

    expect(fn() => budgetMovementService()->reverseMovement($movimento, $userId))
        ->toThrow(ValidationException::class);
});