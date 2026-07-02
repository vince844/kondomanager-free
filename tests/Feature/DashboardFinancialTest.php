<?php

use App\Enums\StatoPianoRate;
use App\Models\User;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

function setupFinancialScenario(int|float $preventivo, int|float $pianificato, bool $connesso = true) {
    // 1. Permessi minimi per evitare crash del layout
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    
    // 2. Creiamo il condominio
    $condominio = Condominio::factory()->create();
    
    // 3. Esercizio: Date pazzescamente ampie per non sbagliare mai
    $esercizio = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato' => 'aperto', 
        'data_inizio' => '1900-01-01', 
        'data_fine' => '2100-12-31',
    ]);

    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio->gestioni()->attach($gestione->id, ['attiva' => true]);

    $pianoConti = PianoConto::factory()->create([
        'gestione_id' => $gestione->id, 
        'condominio_id' => $condominio->id
    ]);

    $padre = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id' => null,
        'importo' => 0 
    ]);

    Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id' => $padre->id,
        'importo' => $preventivo 
    ]);

    $pianoRate = PianoRate::factory()->create(['gestione_id' => $gestione->id]);
    if ($connesso) { 
        // FIX: Inseriamo l'importo pianificato nella Pivot!
        $pianoRate->capitoli()->attach($padre->id, ['importo' => $pianificato]); 
    }

    Rata::factory()->create(['piano_rate_id' => $pianoRate->id, 'importo_totale' => $pianificato]);

    return compact('user', 'condominio');
}

test('dashboard calcola correttamente un deficit di budget', function () {
    $data = setupFinancialScenario(100000, 80000);
    
    // Proviamo a non usare withoutMiddleware() ma a dare i permessi,
    // se fallisce rimetti withoutMiddleware()
    $this->actingAs($data['user'])
        ->get("/admin/gestionale/{$data['condominio']->id}")
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('copertura', fn ($json) => $json
                ->where('preventivo', 100000)
                ->where('pianificato', 80000)
                ->where('delta', 20000)
                ->etc()
            )
        );
});

test('dashboard risulta allineata', function () {
    $data = setupFinancialScenario(50000, 50000);

    $this->actingAs($data['user'])
        ->get("/admin/gestionale/{$data['condominio']->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('copertura.is_completo', true)
        );
});

test('dashboard somma correttamente rate da più piani per la stessa voce (Scenario Multi-Piano)', function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    /** @var \App\Models\User $user */
    $user = User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $condominio = Condominio::factory()->create();
    
    $esercizio = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato' => 'aperto', 
        'data_inizio' => '2026-01-01', 
        'data_fine' => '2026-12-31',
    ]);

    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    
    // *** CORREZIONE: Colleghiamo la gestione all'esercizio ***
    $esercizio->gestioni()->attach($gestione->id, ['attiva' => true]);

    $pianoConti = PianoConto::factory()->create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id]);
    $padre = Conto::factory()->create(['piano_conto_id' => $pianoConti->id, 'importo' => 0]); 
    $contoSpesa = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 
        'parent_id' => $padre->id, 
        'importo' => 100000 
    ]);

    $pianoA = PianoRate::factory()->create(['gestione_id' => $gestione->id, 'descrizione' => 'Rate Ordinarie']);
    // FIX: Aggiunto ['importo' => 60000]
    $pianoA->capitoli()->attach($padre->id, ['importo' => 60000]); 
    Rata::factory()->create(['piano_rate_id' => $pianoA->id, 'importo_totale' => 60000]);

    $pianoB = PianoRate::factory()->create(['gestione_id' => $gestione->id, 'descrizione' => 'Conguaglio']);
    // FIX: Aggiunto ['importo' => 40000]
    $pianoB->capitoli()->attach($padre->id, ['importo' => 40000]);
    Rata::factory()->create(['piano_rate_id' => $pianoB->id, 'importo_totale' => 40000]);

    $this->actingAs($user)
        ->get("/admin/gestionale/{$condominio->id}")
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('gestionale/dashboard/Dashboard')
            ->has('copertura', fn ($json) => $json
                ->where('preventivo', 100000)   
                ->where('pianificato', 100000) 
                ->where('delta', 0)
                ->where('is_completo', true)
                ->etc()
            )
        );
});

test('dashboard identifica la singola voce responsabile del deficit', function () {
    // Setup: Spesa 1.000€, Rata 900€ -> Buco 100€
    $data = setupFinancialScenario(100000, 90000); 

    $this->actingAs($data['user'])
        ->get("/admin/gestionale/{$data['condominio']->id}")
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('copertura.orfani', 1) // Ci aspettiamo 1 voce nella lista dei problemi
            ->has('copertura.orfani.0', fn ($json) => $json
                ->where('importo', 10000) // 100,00€ di buco
                // Qui verifichiamo che ci sia il suggerimento giusto
                ->where('strategia', 'nessuna') 
                ->etc()
            )
        );
});

test('impedisce la modifica dell\'importo spesa se esistono rate emesse', function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    /** @var \App\Models\User $user */
    $user = User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $condominio = Condominio::factory()->create();
    
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio->gestioni()->attach($gestione->id, ['attiva' => true]);
    $pianoConti = PianoConto::factory()->create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id]);
    $conto = Conto::factory()->create(['piano_conto_id' => $pianoConti->id, 'importo' => 50000]);
    
    // *** CORREZIONE: Usiamo l'Enum corretto (o 'approvato' se non hai l'enum sotto mano) ***
    // Assumo che tu abbia StatoPianoRate::APPROVATO, se dà errore usa la stringa 'approvato'
    $stato = enum_exists(StatoPianoRate::class) ? StatoPianoRate::APPROVATO : 'approvato';

    $pianoRate = PianoRate::factory()->create(['gestione_id' => $gestione->id, 'stato' => $stato]);  
    // FIX: Anche qui per coerenza, esplicitiamo l'importo 
    $pianoRate->capitoli()->attach($conto->id, ['importo' => 50000]);

    $response = $this->actingAs($user)
        ->patchJson("/admin/gestionale/{$condominio->id}/esercizi/{$esercizio->id}/piani-conti/{$pianoConti->id}/conti/{$conto->id}", [
            'importo' => 60000,
            'nome' => 'Spesa Modificata'
        ]);

            dump($response->getContent());

    // Verifichiamo che sia 422 (Unprocessable) o 403 (Forbidden)
    $response->assertStatus(422); 
});