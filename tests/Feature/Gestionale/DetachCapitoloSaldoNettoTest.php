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
 * La guardia che blocca la rimozione di una voce coinvolta in uno Sposta Spesa — beta.73.
 *
 * Fino a questa beta il blocco guardava se la voce era MAI comparsa in un movimento, in
 * QUALUNQUE piano rate, e restava per sempre perché non esisteva nessuno storno. Ora guarda il
 * saldo netto DI QUESTO piano: uno storno completo lo riporta a zero, e la rimozione torna
 * possibile — esattamente quello che il messaggio d'errore ha sempre promesso.
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

function setupPianoDueVociETreRate(): array
{
    [$condominio, $esercizio, $gestione, , $source, , $immobileId] = setupContabile();

    $destId = DB::table('conti')->insertGetId([
        'piano_conto_id'     => $source->piano_conto_id,
        'conto_contabile_id' => $source->conto_contabile_id,
        'nome'               => 'Voce Destinazione Test',
        'tipo'               => 'spesa',
        'importo'            => 200000,
        'is_tecnico'         => false,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    $dest = Conto::find($destId);

    // Millesimi minimi perché GeneratePianoRateAction sappia calcolare le quote dopo la rimozione:
    // una tabella, un'unità, un condòmino — collegata a entrambe le voci.
    $tabellaId = DB::table('tabelle')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Proprietà Test', 'tipo' => 'standard',
        'quota' => 'millesimi', 'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabellaId, 'immobile_id' => $immobileId, 'valore' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $anagraficaId = DB::table('anagrafiche')->insertGetId([
        'nome' => 'Condòmino Test', 'indirizzo' => 'Via Test 1', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagraficaId, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario',
        'quota' => 100, 'attivo' => true, 'data_inizio' => now()->subYear(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([$source->id, $destId] as $contoId) {
        $assocId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id' => $contoId, 'tabella_id' => $tabellaId, 'coefficiente' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $assocId, 'soggetto' => 'proprietario', 'percentuale' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $piano = PianoRate::create([
        'gestione_id'   => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome'          => 'Piano Detach Test',
        'numero_rate'   => 1,
        'stato'         => 'bozza',
    ]);

    DB::table('piano_rate_capitoli')->insert([
        ['piano_rate_id' => $piano->id, 'conto_id' => $source->id, 'importo' => 100000, 'note' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return [$condominio, $piano, $source, $dest];
}

it('blocca la rimozione se la voce ha ancora un saldo netto diverso da zero', function () {
    [$condominio, $piano, $source, $dest] = setupPianoDueVociETreRate();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id,
    ]), ['source_id' => $source->id, 'destination_id' => $dest->id, 'amount' => '300.00', 'reason' => 'Test']);

    $response = $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [
        'condominio' => $condominio->id, 'esercizio' => $piano->gestione->esercizi()->first()->id ?? DB::table('esercizio_gestione')->where('gestione_id', $piano->gestione_id)->value('esercizio_id'),
        'pianoRate' => $piano->id, 'capitolo' => $dest->id,
    ]));

    // La voce esiste ancora nel piano: la rimozione è stata rifiutata.
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->exists())->toBeTrue();
});

it('permette la rimozione dopo che il movimento è stato stornato per intero', function () {
    [$condominio, $piano, $source, $dest] = setupPianoDueVociETreRate();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id,
    ]), ['source_id' => $source->id, 'destination_id' => $dest->id, 'amount' => '300.00', 'reason' => 'Test']);

    $movimento = BudgetMovement::where('piano_rate_id', $piano->id)->firstOrFail();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [
        'condominio' => $condominio->id, 'pianoRate' => $piano->id, 'budgetMovement' => $movimento->id,
    ]))->assertSessionHasNoErrors();

    $esercizioId = DB::table('esercizio_gestione')->where('gestione_id', $piano->gestione_id)->value('esercizio_id');

    // ⚠️ Il controller usa un flash custom (chiave "message", non gli "errors" di Laravel):
    // assertSessionHasNoErrors() non lo vedrebbe comunque, va letto il flash direttamente.
    $risposta = $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [
        'condominio' => $condominio->id, 'esercizio' => $esercizioId, 'pianoRate' => $piano->id, 'capitolo' => $dest->id,
    ]));
    expect($risposta->getSession()->get('message')['type'] ?? null)->not->toBe('error');

    // Ora la voce non è più nel piano: la rimozione è passata.
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->exists())->toBeFalse();
});

it('non blocca la rimozione di una voce toccata da movimenti su un piano rate diverso', function () {
    [$condominioA, $pianoA, $sourceA, $destA] = setupPianoDueVociETreRate();

    // Una seconda voce nel piano A, così rimuovendo sourceA il piano non resta vuoto
    // (GeneratePianoRateAction ha comunque qualcosa da calcolare).
    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $pianoA->id, 'conto_id' => $destA->id, 'importo' => 50000,
        'note' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Un secondo piano, stesso piano dei conti, con la STESSA voce destA come sorgente.
    $pianoB = PianoRate::create([
        'gestione_id'   => $pianoA->gestione_id,
        'condominio_id' => $condominioA->id,
        'nome'          => 'Piano B Test',
        'numero_rate'   => 1,
        'stato'         => 'bozza',
    ]);
    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $pianoB->id, 'conto_id' => $destA->id, 'importo' => 50000,
        'note' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [
        'condominio' => $condominioA->id, 'pianoRate' => $pianoB->id,
    ]), ['source_id' => $destA->id, 'destination_id' => $sourceA->id, 'amount' => '100.00', 'reason' => 'Su piano B']);

    // destA non ha MAI avuto un movimento sul piano A: rimuoverla da lì deve funzionare,
    // anche se altrove (piano B) ha una storia.
    $esercizioId = DB::table('esercizio_gestione')->where('gestione_id', $pianoA->gestione_id)->value('esercizio_id');
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [
        'condominio' => $condominioA->id, 'esercizio' => $esercizioId, 'pianoRate' => $pianoA->id, 'capitolo' => $sourceA->id,
    ]));

    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $pianoA->id)->where('conto_id', $sourceA->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------------
// B2 (1.11.0-beta.31), cancello (2) della decisione 14 — verifica indipendente S4 del 19/09/2026.
// Fino a S4 la rimozione di una voce passava il cancello da sola, con una presa d'atto e una nota
// scritte dal programma: ma il piano precedente può non aver mai attraversato il cancello (generato
// atemporale, e il titolare chiuso dopo). La presa d'atto è dell'amministratore.
// ---------------------------------------------------------------------------------------------------

it('la rimozione di una voce si ferma davanti al cancello (2) se il ricalcolo cambia i destinatari, e riparte con spunta e nota', function () {
    [$condominio, $piano, $source, $dest] = setupPianoDueVociETreRate();
    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $piano->id, 'conto_id' => $dest->id, 'importo' => 50000, 'note' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $esercizioId = DB::table('esercizio_gestione')->where('gestione_id', $piano->gestione_id)->value('esercizio_id');
    $immobileId = DB::table('anagrafica_immobile')->value('immobile_id');

    // Il piano generato com'era (un solo proprietario), poi il proprietario vende il 30/04 e l'acquirente
    // decorre dal 01/05 — registrato dall'elenco titolari, senza rigenerare.
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, esercizio: \App\Models\Esercizio::find($esercizioId));
    DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)->update(['data_inizio' => '2019-03-03', 'data_fine' => '2026-04-30']);
    $acquirenteId = DB::table('anagrafiche')->insertGetId(['nome' => 'Acquirente Test', 'indirizzo' => 'Via Test 2', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $acquirenteId, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario',
        'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-05-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rotta = route('admin.gestionale.piani-rate.capitoli.detach', [
        'condominio' => $condominio->id, 'esercizio' => $esercizioId, 'pianoRate' => $piano->id, 'capitolo' => $dest->id,
    ]);

    // (1) Senza presa d'atto: il pannello, e la voce è ancora nel piano (il rollback annulla il detach).
    $risposta = $this->actingAs($this->user)->delete($rotta);
    expect($risposta->getSession()->get('destinatari_warning'))->toBeArray()->not->toBeEmpty()
        ->and($risposta->getSession()->get('destinatari_warning_detach'))->toBe($dest->id)
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->exists())->toBeTrue();

    // (2) Nota troppo corta: errore di validazione, niente tocco al piano.
    $this->actingAs($this->user)->delete($rotta, ['accetta_destinatari' => true, 'nota_destinatari' => 'corta'])
        ->assertSessionHasErrors('nota_destinatari');

    // (3) Con spunta e nota: la voce esce e il piano si ricalcola pro rata, con la nota congelata.
    $risposta = $this->actingAs($this->user)->delete($rotta, ['accetta_destinatari' => true, 'nota_destinatari' => 'Rogito del 30 aprile, letto il pannello']);
    expect($risposta->getSession()->get('message')['type'] ?? null)->toBe('success')
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $dest->id)->exists())->toBeFalse();
    $quota = \App\Models\Gestionale\RataQuote::whereHas('rata', fn ($q) => $q->where('piano_rate_id', $piano->id))->first();
    expect($quota->regole_calcolo['parametri']['titolarita_alla']['nota_cancello'])->toBe('Rogito del 30 aprile, letto il pannello');
});
