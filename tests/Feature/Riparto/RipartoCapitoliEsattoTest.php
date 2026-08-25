<?php

use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoTabellaMillesimale;
use App\Services\CalcoloQuoteService;
use App\Services\RipartoCapitoliService;

it('riproduce il riparto per capitoli del condominio T12', function () {
    // 1. Setup Condominio
    $condominio = \App\Models\Condominio::factory()->create();
    
    // 2. Setup Tabelle
    $tabGen = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Generale', 'quota' => 'quote']);
    $tabAsc = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Ascensore', 'quota' => 'quote']);
    $tabRisc = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Riscaldamento', 'quota' => 'quote']);

    // 3. Setup Anagrafiche
    $nardelli = \App\Models\Anagrafica::factory()->create(['nome' => 'NARDELLI']);
    // Nardelli non ha inquilino, ha il 100% di spese
    $immobileNardelli = \App\Models\Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '1', 'nome' => 'App 1', 'codice_immobile' => 'C1-001', 'descrizione' => 'Test']);
    $immobileNardelli->anagrafiche()->attach($nardelli->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);

    \App\Models\QuotaTabella::create(['tabella_id' => $tabGen->id, 'immobile_id' => $immobileNardelli->id, 'valore' => 166.666]);
    \App\Models\QuotaTabella::create(['tabella_id' => $tabAsc->id, 'immobile_id' => $immobileNardelli->id, 'valore' => 83.333]);
    \App\Models\QuotaTabella::create(['tabella_id' => $tabRisc->id, 'immobile_id' => $immobileNardelli->id, 'valore' => 161.192]);

    $ruattiP = \App\Models\Anagrafica::factory()->create(['nome' => 'RUATTI (P)']);
    $ruattiI = \App\Models\Anagrafica::factory()->create(['nome' => 'RUATTI (I)']);
    $immobileRuatti = \App\Models\Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '2', 'nome' => 'App 2', 'codice_immobile' => 'C1-002', 'descrizione' => 'Test']);
    $immobileRuatti->anagrafiche()->attach($ruattiP->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    $immobileRuatti->anagrafiche()->attach($ruattiI->id, ['tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    
    \App\Models\QuotaTabella::create(['tabella_id' => $tabGen->id, 'immobile_id' => $immobileRuatti->id, 'valore' => 166.666]);
    \App\Models\QuotaTabella::create(['tabella_id' => $tabAsc->id, 'immobile_id' => $immobileRuatti->id, 'valore' => 166.666]);
    \App\Models\QuotaTabella::create(['tabella_id' => $tabRisc->id, 'immobile_id' => $immobileRuatti->id, 'valore' => 167.575]);

    for ($i = 0; $i < 4; $i++) {
        $extraP = \App\Models\Anagrafica::factory()->create(['nome' => 'EXTRA ' . $i]);
        $immobileExtra = \App\Models\Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string)($i+3), 'nome' => "App " . ($i+3), 'codice_immobile' => "C1-00" . ($i+3), 'descrizione' => 'Test']);
        $immobileExtra->anagrafiche()->attach($extraP->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
        \App\Models\QuotaTabella::create(['tabella_id' => $tabGen->id, 'immobile_id' => $immobileExtra->id, 'valore' => 166.667]);
        \App\Models\QuotaTabella::create(['tabella_id' => $tabAsc->id, 'immobile_id' => $immobileExtra->id, 'valore' => 187.500]);
        \App\Models\QuotaTabella::create(['tabella_id' => $tabRisc->id, 'immobile_id' => $immobileExtra->id, 'valore' => 167.808]); // 161.192 + 167.575 + 167.808*4 = 999.999 (close enough)
    }

    $esercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $condominio->id]);
    $gestione = \App\Models\Gestione::factory()->create(['condominio_id' => $condominio->id]);
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id]);
    
    $contoRisc = Conto::create(['piano_conto_id' => $pianoConti->id, 'nome' => 'Riscaldamento', 'tipo' => 'spesa', 'importo' => 1530000]);
    ContoTabellaMillesimale::create(['conto_id' => $contoRisc->id, 'tabella_id' => $tabRisc->id, 'coefficiente' => 100]);

    $pianoRate = PianoRate::factory()->create(['gestione_id' => $gestione->id]);

    // Usa il nuovo service Capitoli
    $ripartoService = new RipartoCapitoliService();
    $matrice = $ripartoService->buildMatrice($pianoRate);

    expect($matrice['tot_per_capitolo'][$contoRisc->id])->toBe(1530000);
});
