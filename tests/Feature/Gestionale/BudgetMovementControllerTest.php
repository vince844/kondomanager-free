<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Models\Gestionale\BudgetMovement;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lo storno di un movimento di Sposta Spesa, a livello HTTP — beta.73.
 *
 * Prima di questa beta la rotta non esisteva: il messaggio che blocca la rimozione di una voce
 * coinvolta diceva da anni «devi annullare questi movimenti (restituendo i fondi)», senza che
 * nessuna azione lo rendesse possibile. Questi test coprono la rotta vera, non solo il service.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Un piano rate con due voci nello stesso piano dei conti, e un movimento fra le due. */
function setupPianoConMovimento(): array
{
    [$condominio, $esercizio, $gestione, , $source] = setupContabile();

    $pianoContoId = $source->piano_conto_id;

    $destId = DB::table('conti')->insertGetId([
        'piano_conto_id'     => $pianoContoId,
        'conto_contabile_id' => $source->conto_contabile_id,
        'nome'               => 'Voce Destinazione Test',
        'tipo'               => 'spesa',
        'importo'            => 200000,
        'is_tecnico'         => false,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    $dest = Conto::find($destId);

    $piano = PianoRate::create([
        'gestione_id'   => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome'          => 'Piano HTTP Test',
        'numero_rate'   => 1,
        'stato'         => 'bozza',
    ]);

    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $piano->id,
        'conto_id'      => $source->id,
        'importo'       => 100000,
        'note'          => null,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return [$condominio, $piano, $source, $dest, $esercizio, $gestione];
}

it('storna un movimento dalla rotta vera e ripristina i pivot', function () {
    [$condominio, $piano, $source, $dest] = setupPianoConMovimento();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id,
    ]), [
        'source_id' => $source->id, 'destination_id' => $dest->id,
        'amount' => '300.00', 'reason' => 'Emergenza test',
    ])->assertSessionHasNoErrors();

    $movimento = BudgetMovement::where('piano_rate_id', $piano->id)->firstOrFail();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id, 'budgetMovement' => $movimento->id,
    ]))->assertSessionHasNoErrors();

    expect(BudgetMovement::where('reverses_movement_id', $movimento->id)->count())->toBe(1);

    $sourcePivot = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)->where('conto_id', $source->id)->value('importo');
    expect($sourcePivot)->toBe(100000);
});

it('rifiuta di stornare due volte lo stesso movimento, anche dalla rotta', function () {
    [$condominio, $piano, $source, $dest] = setupPianoConMovimento();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id,
    ]), [
        'source_id' => $source->id, 'destination_id' => $dest->id,
        'amount' => '300.00', 'reason' => 'Emergenza test',
    ]);

    $movimento = BudgetMovement::where('piano_rate_id', $piano->id)->firstOrFail();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id, 'budgetMovement' => $movimento->id,
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id, 'budgetMovement' => $movimento->id,
    ]))->assertSessionHasErrors();

    expect(BudgetMovement::where('reverses_movement_id', $movimento->id)->count())->toBe(1);
});

it('rifiuta di stornare uno storno, dalla rotta', function () {
    [$condominio, $piano, $source, $dest] = setupPianoConMovimento();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id,
    ]), [
        'source_id' => $source->id, 'destination_id' => $dest->id,
        'amount' => '300.00', 'reason' => 'Emergenza test',
    ]);

    $originale = BudgetMovement::where('piano_rate_id', $piano->id)->firstOrFail();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id, 'budgetMovement' => $originale->id,
    ]))->assertSessionHasNoErrors();

    $storno = BudgetMovement::where('reverses_movement_id', $originale->id)->firstOrFail();

    // Provo a stornare lo storno stesso: la catena avanti-indietro deve fermarsi qui.
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id, 'budgetMovement' => $storno->id,
    ]))->assertSessionHasErrors();

    expect(BudgetMovement::where('piano_rate_id', $piano->id)->count())->toBe(2);
});

it('rifiuta uno storno il cui movimento appartiene a un altro piano rate', function () {
    [$condominioA, $pianoA, $sourceA, $destA] = setupPianoConMovimento();
    [$condominioB, $pianoB] = setupPianoConMovimento();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [
        'condominio' => $condominioA->id, 'pianoRate' => $pianoA->id,
    ]), [
        'source_id' => $sourceA->id, 'destination_id' => $destA->id,
        'amount' => '300.00', 'reason' => 'Emergenza test',
    ]);

    $movimentoDiA = BudgetMovement::where('piano_rate_id', $pianoA->id)->firstOrFail();

    // Provo a stornarlo passando dal piano B: id del movimento vero, ma piano sbagliato nell'URL.
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [
        'condominio' => $condominioB->id, 'pianoRate' => $pianoB->id, 'budgetMovement' => $movimentoDiA->id,
    ]))->assertNotFound();
});

/**
 * Il badge «Da Sposta Spesa» nel selettore di un piano nuovo — trovato in Fase 1-bis.
 *
 * Prima del fix la query leggeva "questa voce è mai comparsa come source_conto_id", senza
 * nettare gli storni: una voce stornata per intero restava marcata «Da Sposta Spesa» anche a
 * saldo netto zero, dicendo una cosa non più vera all'amministratore che crea un nuovo piano.
 */
it('il badge Da Sposta Spesa sparisce dal selettore dopo uno storno completo', function () {
    [$condominio, $piano, $source, $dest, $esercizio, $gestione] = setupPianoConMovimento();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id,
    ]), [
        'source_id' => $source->id, 'destination_id' => $dest->id,
        'amount' => '300.00', 'reason' => 'Emergenza test',
    ])->assertSessionHasNoErrors();

    $primaDelloStorno = $this->actingAs($this->user)->get(route('admin.gestionale.fetch-capitoli-gestione', [
        'condominio' => $condominio->id,
    ]).'?gestione_id='.$gestione->id.'&esercizio_id='.$esercizio->id)->json();

    $vociSource = collect($primaDelloStorno)->firstWhere('id', $source->id);
    expect($vociSource['da_sposta_spesa'])->toBeTrue();

    $movimento = BudgetMovement::where('piano_rate_id', $piano->id)->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id, 'budgetMovement' => $movimento->id,
    ]))->assertSessionHasNoErrors();

    $dopoLoStorno = $this->actingAs($this->user)->get(route('admin.gestionale.fetch-capitoli-gestione', [
        'condominio' => $condominio->id,
    ]).'?gestione_id='.$gestione->id.'&esercizio_id='.$esercizio->id)->json();

    $vociSourceDopo = collect($dopoLoStorno)->firstWhere('id', $source->id);
    expect($vociSourceDopo['da_sposta_spesa'])->toBeFalse();
});
