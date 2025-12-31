<?php

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// --- HELPER DI SETUP (Blindato e Testato) ---
function createScenarioPest() {
    // 1. Condominio
    $condominio = Condominio::create([
        'nome' => 'Condominio Test',
        'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI'
    ]);

    // 2. Esercizio
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2025',
        'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto'
    ]);
    
    // 3. Gestione
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Ordinaria 2025',
        'data_inizio' => '2025-01-01', 'tipo' => 'ordinaria'
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    // 4. Anagrafica (Proprietario storico)
    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@test.it',
        'indirizzo' => 'Via Verdi 10', 'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM',
        'codice_fiscale' => 'RSSMRA80A01H501U'
    ]);

    // 5. Tabella (Enum corretti)
    $tabellaGen = Tabella::create([
        'condominio_id' => $condominio->id, 
        'nome' => 'Generale',
        'tipo' => 'standard',   
        'quota' => 'millesimi',
        'attiva' => true
    ]);

    // 6. Immobile (Campi obbligatori presenti)
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Int 1',
        'descrizione' => 'Appartamento residenziale',
        'interno' => '1',
        'foglio' => '1', 'particella' => '100', 'subalterno' => '1'
    ]);
    
    // Collega Immobile a Anagrafica
    $immobile->anagrafiche()->attach($anagrafica->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()->subYear()
    ]);

    // Collega Immobile a Tabella (Nome tabella: quote_tabella)
    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabellaGen->id,
        'immobile_id' => $immobile->id,
        'valore' => 1000,
        'created_at' => now(), 'updated_at' => now()
    ]);

    // 7. Piano Conti
    $pianoConti = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Preventivo 2025'
    ]);

    // --- HELPER COLLEGAMENTO CONTO-TABELLA & RIPARTIZIONE ---
    $collegaTabella = function($contoId, $tabellaId) {
        if(Schema::hasTable('conto_tabella_millesimale')) {
            // 1. Pivot millesimale
             $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
                'conto_id' => $contoId,
                'tabella_id' => $tabellaId,
                'coefficiente' => 100,
                'created_at' => now(), 'updated_at' => now()
            ]);

            // 2. Ripartizione (Chi paga? Proprietario)
            if(Schema::hasTable('conto_tabella_ripartizioni')) {
                DB::table('conto_tabella_ripartizioni')->insert([
                    'conto_tabella_millesimale_id' => $pivotId,
                    'soggetto' => 'proprietario', 
                    'percentuale' => 100,
                    'created_at' => now(), 'updated_at' => now()
                ]);
            }
        }
    };

    // Voce A: Spese Generali (1.000€)
    $voceGenerale = Conto::forceCreate([
        'piano_conto_id' => $pianoConti->id,
        'nome' => 'Spese Generali',
        'importo' => 100000,  
        'tipo' => 'spesa',
        'attivo' => true
    ]);
    $collegaTabella($voceGenerale->id, $tabellaGen->id); 

    // Voce B: Scala A (500€)
    $voceScalaA = Conto::forceCreate([
        'piano_conto_id' => $pianoConti->id,
        'nome' => 'Spese Scala A',
        'importo' => 50000,   
        'tipo' => 'spesa',
        'attivo' => true
    ]);
    $collegaTabella($voceScalaA->id, $tabellaGen->id); 

    // Ritorniamo gli oggetti utili per i test
    return (object) [
        'condominio' => $condominio,
        'gestione' => $gestione,
        'capitoloGenerale' => $voceGenerale, 
        'capitoloScalaA' => $voceScalaA 
    ];
}

test('genera piano rate totale senza filtri (default)', function () {
    $data = createScenarioPest(); 

    $pianoRate = PianoRate::create([
        'condominio_id' => $data->condominio->id,
        'gestione_id' => $data->gestione->id,
        'nome' => 'Piano Rate Completo',
        'numero_rate' => 1,
        'metodo_distribuzione' => 'tutte_rate',
        'stato' => 'bozza'
    ]);
    
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    $pianoRate->refresh();
    
    expect($pianoRate->rate)->not->toBeEmpty();

    $totaleQuote = $pianoRate->rate->flatMap->rateQuote->sum('importo');
    
    // 1.500€ attesi
    expect($totaleQuote)->toBe(150000);
});

test('genera piano rate filtrato solo per un capitolo (Scenario Supercondominio)', function () {
    $data = createScenarioPest();

    $pianoRate = PianoRate::create([
        'condominio_id' => $data->condominio->id,
        'gestione_id' => $data->gestione->id,
        'nome' => 'Piano Rate Solo Scala A',
        'numero_rate' => 1,
        'metodo_distribuzione' => 'tutte_rate',
        'stato' => 'bozza'
    ]);

    // Collega solo la voce Scala A
    $pianoRate->capitoli()->attach($data->capitoloScalaA->id);

    app(GeneratePianoRateAction::class)->execute($pianoRate);

    $pianoRate->refresh();
    
    expect($pianoRate->rate)->not->toBeEmpty();

    $totaleQuote = $pianoRate->rate->flatMap->rateQuote->sum('importo');

    // 500€ attesi (Solo Scala A), NON 1.500€
    expect($totaleQuote)->toBe(50000)
        ->and($totaleQuote)->not->toBe(150000);
});

test('verifica la corretta ripartizione dei centesimi su più rate', function () {
    // 1. Setup base
    $data = createScenarioPest();
    
    // 2. Modifica importi: 100,00€ totali per forzare 33,33 periodico
    $data->capitoloGenerale->update(['importo' => 10000]); 
    $data->capitoloScalaA->update(['importo' => 0]);     

    $pianoRate = PianoRate::create([
        'condominio_id' => $data->condominio->id,
        'gestione_id' => $data->gestione->id,
        'nome' => 'Piano 3 Rate Arrotondamento',
        'numero_rate' => 3,
        'metodo_distribuzione' => 'tutte_rate',
        'stato' => 'bozza'
    ]);

    // 3. Esecuzione
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    // 4. Verifiche
    $rate = $pianoRate->rate()->orderBy('numero_rata')->get();
    
    // A. Il totale deve fare esattamente 100€ (10.000 centesimi)
    $tot = $rate->sum('importo_totale');
    expect($tot)->toBe(10000);
    
    // B. Le rate NON devono essere identiche (una deve assorbire il centesimo)
    // Es: 3333, 3333, 3334
    $rateUguali = ($rate[0]->importo_totale === $rate[1]->importo_totale) && 
                  ($rate[0]->importo_totale === $rate[2]->importo_totale);
    
    expect($rateUguali)->toBeFalse();
});