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
use App\Services\RipartoTabelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('distribuisce RISCALDAMENTO proporzionalmente all\'importo non al conteggio voci', function () {
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
        'tipo' => 'ordinaria'
    ]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'nome' => 'PC 2026'
    ]);

    // TABELLE
    $tabellaRisc = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => 'RISCALDAMENTO',
        'tipo' => 'standard',
        'quota' => 'millesimi',
        'attiva' => true
    ]);
    $riscTabId = $tabellaRisc->id;

    $tabellaGen = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => 'GENERALE',
        'tipo' => 'standard',
        'quota' => 'millesimi',
        'attiva' => true
    ]);

    // IMMOBILE E ANAGRAFICA
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'tipo' => 'appartamento',
        'codice_immobile' => 'C1-001',
        'nome' => 'Int 1',
        'interno' => '1',
        'descrizione' => 'Test Desc'
    ]);

    // Millesimi
    DB::table('quote_tabella')->insert([
        ['tabella_id' => $tabellaRisc->id, 'immobile_id' => $immobile->id, 'valore' => 156.404, 'created_at' => now(), 'updated_at' => now()],
        ['tabella_id' => $tabellaGen->id, 'immobile_id' => $immobile->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]
    ]);

    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id,
        'immobile_id' => $immobile->id,
        'tipologia' => 'inquilino',
        'quota' => 100,
        'attivo' => true,
        'data_inizio' => now()
    ]);

    // CONTI RISCALDAMENTO: 300, 1000, 14000
    $importiRisc = [30000, 100000, 1400000]; // in cents
    foreach ($importiRisc as $idx => $imp) {
        $contoRisc = Conto::create([
            'piano_conto_id' => $pianoConto->id,
            'nome' => "Voce Risc $idx",
            'tipo' => 'spesa',
            'importo' => $imp
        ]);
        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id' => $contoRisc->id,
            'tabella_id' => $tabellaRisc->id,
            'coefficiente' => 100,
            'created_at' => now(), 'updated_at' => now()
        ]);
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivotId,
            'soggetto' => 'inquilino',
            'percentuale' => 100,
            'created_at' => now(), 'updated_at' => now()
        ]);
    }

    // CONTO GENERALE: 1000 (100000 cents)
    $contoGen = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome' => "Voce Gen",
        'tipo' => 'spesa',
        'importo' => 100000
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $contoGen->id,
        'tabella_id' => $tabellaGen->id,
        'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now()
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId,
        'soggetto' => 'inquilino',
        'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now()
    ]);

    // Genera Piano Rate
    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome' => 'Piano di Prova',
        'stato' => 'bozza',
        'tipo' => 'ordinario',
        'numero_rate' => 1
    ]);

    $action = app(GeneratePianoRateAction::class);
    $action->execute($pianoRate);

    // Call service
    $service = new RipartoTabelleService();
    $matrice = $service->buildMatrice($pianoRate);
    
    $riscTotale = $matrice['tot_per_tabella'][$riscTabId];
    
    expect($riscTotale)->toBe(1530000); // 15300 in cents, penny-perfect
});
