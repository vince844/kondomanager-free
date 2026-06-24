<?php

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Exceptions\Gestionale\ScopertiNonAccettatiException;
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

uses(RefreshDatabase::class);

function createScenarioPerPianoRate(): PianoRate
{
    $condominio = Condominio::factory()->create();
    
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto'
    ]);
    
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'nome' => 'Gestione Base',
        'tipo' => 'ordinaria', 'descrizione' => 'Gestione Base'
    ]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'nome' => 'PC 2026'
    ]);

    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome' => 'Spese Inquilino',
        'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria',
        'importo' => 100000,
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Tabella Test Inquilino',
        'tipo' => 'standard',
        'quota' => 'millesimi',
        'attiva' => true
    ]);

    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id,
        'tabella_id' => $tabella->id,
        'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now()
    ]);

    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId,
        'soggetto' => 'inquilino',
        'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now()
    ]);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome' => 'Piano di Prova',
        'stato' => 'bozza',
        'tipo' => 'ordinario'
    ]);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'tipo' => 'appartamento',
        'codice_immobile' => 'C1-001',
        'nome' => 'Int 1',
        'descrizione' => 'Senza Anagrafica',
        'interno' => '1',
        'scala' => 'A'
    ]);
    
    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id,
        'immobile_id' => $immobile->id,
        'valore' => 500.0,
        'created_at' => now(), 'updated_at' => now()
    ]);

    $immobile2 = Immobile::create([
        'condominio_id' => $condominio->id,
        'tipo' => 'appartamento',
        'codice_immobile' => 'C1-002',
        'nome' => 'Int 2',
        'descrizione' => 'Con Anagrafica',
        'interno' => '2',
        'scala' => 'B'
    ]);

    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id,
        'immobile_id' => $immobile2->id,
        'valore' => 500.0,
        'created_at' => now(), 'updated_at' => now()
    ]);

    $anagrafica = \App\Models\Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id,
        'immobile_id' => $immobile2->id,
        'tipologia' => 'inquilino',
        'quota' => 100,
        'attivo' => true,
        'data_inizio' => now()
    ]);

    return $pianoRate;
}

test('action lancia ScopertiNonAccettatiException se ci sono scoperti e flag false', function () {
    $pianoRate = createScenarioPerPianoRate();
    
    $action = app(GeneratePianoRateAction::class);

    expect(fn () => $action->execute($pianoRate, accettaScoperti: false))
        ->toThrow(ScopertiNonAccettatiException::class);
});

test('action procede e persiste nota quando accettaScoperti è true', function () {
    $pianoRate = createScenarioPerPianoRate();
    $action = app(GeneratePianoRateAction::class);

    $action->execute($pianoRate, accettaScoperti: true, notaScoperti: 'Test motivazione');
    
    $pianoRate->refresh();
    expect($pianoRate->nota_scoperti)->toBe('Test motivazione');
});
