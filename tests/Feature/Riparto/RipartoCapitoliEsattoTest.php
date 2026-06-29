<?php

use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoTabellaMillesimale;
use App\Services\CalcoloQuoteService;
use App\Services\RipartoCapitoliService;

it('riproduce il riparto per capitoli del condominio T12', function () {
    $this->artisan('migrate:fresh');

    // 1. Setup Condominio
    $condominio = \App\Models\Condominio::factory()->create();
    
    // 2. Setup Tabelle
    $tabGen = Tabella::factory()->create(['condominio_id' => $condominio->id, 'nome' => 'Generale']);
    $tabAsc = Tabella::factory()->create(['condominio_id' => $condominio->id, 'nome' => 'Ascensore']);
    $tabRisc = Tabella::factory()->create(['condominio_id' => $condominio->id, 'nome' => 'Riscaldamento']);

    // 3. Setup Anagrafiche
    $nardelli = \App\Models\Anagrafica::factory()->create(['nome' => 'NARDELLI']);
    // Nardelli non ha inquilino, ha il 100% di spese
    $immobileNardelli = \App\Models\Immobile::factory()->create(['condominio_id' => $condominio->id]);
    $immobileNardelli->anagrafiche()->attach($nardelli->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true]);

    \App\Models\QuotaMillesimale::create(['tabella_id' => $tabGen->id, 'immobile_id' => $immobileNardelli->id, 'valore' => 166.666]);
    \App\Models\QuotaMillesimale::create(['tabella_id' => $tabAsc->id, 'immobile_id' => $immobileNardelli->id, 'valore' => 83.333]);
    \App\Models\QuotaMillesimale::create(['tabella_id' => $tabRisc->id, 'immobile_id' => $immobileNardelli->id, 'valore' => 161.192]);

    $ruattiP = \App\Models\Anagrafica::factory()->create(['nome' => 'RUATTI (P)']);
    $ruattiI = \App\Models\Anagrafica::factory()->create(['nome' => 'RUATTI (I)']);
    $immobileRuatti = \App\Models\Immobile::factory()->create(['condominio_id' => $condominio->id]);
    $immobileRuatti->anagrafiche()->attach($ruattiP->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true]);
    $immobileRuatti->anagrafiche()->attach($ruattiI->id, ['tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true]);
    
    \App\Models\QuotaMillesimale::create(['tabella_id' => $tabGen->id, 'immobile_id' => $immobileRuatti->id, 'valore' => 166.666]);
    \App\Models\QuotaMillesimale::create(['tabella_id' => $tabAsc->id, 'immobile_id' => $immobileRuatti->id, 'valore' => 166.666]);
    \App\Models\QuotaMillesimale::create(['tabella_id' => $tabRisc->id, 'immobile_id' => $immobileRuatti->id, 'valore' => 167.575]);

    for ($i = 0; $i < 4; $i++) {
        $extraP = \App\Models\Anagrafica::factory()->create(['nome' => 'EXTRA ' . $i]);
        $immobileExtra = \App\Models\Immobile::factory()->create(['condominio_id' => $condominio->id]);
        $immobileExtra->anagrafiche()->attach($extraP->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true]);
        \App\Models\QuotaMillesimale::create(['tabella_id' => $tabGen->id, 'immobile_id' => $immobileExtra->id, 'valore' => 166.667]);
        \App\Models\QuotaMillesimale::create(['tabella_id' => $tabAsc->id, 'immobile_id' => $immobileExtra->id, 'valore' => 187.500]);
        \App\Models\QuotaMillesimale::create(['tabella_id' => $tabRisc->id, 'immobile_id' => $immobileExtra->id, 'valore' => 167.808]); // 161.192 + 167.575 + 167.808*4 = 999.999 (close enough)
    }

    $esercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $condominio->id]);
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id]);
    
    $contoRisc = Conto::create(['piano_conto_id' => $pianoConti->id, 'nome' => 'Riscaldamento', 'importo' => 1530000]);
    ContoTabellaMillesimale::create(['conto_id' => $contoRisc->id, 'tabella_id' => $tabRisc->id, 'coefficiente' => 100]);

    $pianoRate = PianoRate::factory()->create(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id]);

    // Usa il nuovo service Capitoli
    $ripartoService = new RipartoCapitoliService();
    $matrice = $ripartoService->buildMatrice($pianoRate);

    expect($matrice['tot_per_capitolo'][$contoRisc->id])->toBe(1530000);
});
