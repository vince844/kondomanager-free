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

it('riproduce il bug delle quote identiche e dei 4 centesimi', function () {
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
    $tabellaGen = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => 'GENERALE',
        'tipo' => 'standard',
        'quota' => 'millesimi',
        'attiva' => true
    ]);
    
    $tabellaRisc = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => 'RISCALDAMENTO',
        'tipo' => 'standard',
        'quota' => 'millesimi',
        'attiva' => true
    ]);

    $pesiRisc = [
        1 => 156.404,
        2 => 161.191,
        3 => 151.031,
        4 => 144.862,
        5 => 192.393,
        6 => 194.119,
    ];

    $immobili = [];
    $anagrafiche = [];

    // Crea 6 immobili e 6 proprietari
    for ($i = 1; $i <= 6; $i++) {
        $immobili[$i] = Immobile::create([
            'condominio_id' => $condominio->id,
            'tipo' => 'appartamento',
            'codice_immobile' => "C1-00$i",
            'nome' => "Int $i",
            'interno' => (string)$i,
            'descrizione' => "App $i"
        ]);

        // Quote millesimali (1/6 esatto per Generale, preciso per Risc)
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $tabellaGen->id, 'immobile_id' => $immobili[$i]->id, 'valore' => 1000.0 / 6, 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $tabellaRisc->id, 'immobile_id' => $immobili[$i]->id, 'valore' => $pesiRisc[$i], 'created_at' => now(), 'updated_at' => now()]
        ]);

        $anagrafiche[$i] = Anagrafica::factory()->create();
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $anagrafiche[$i]->id,
            'immobile_id' => $immobili[$i]->id,
            'tipologia' => 'proprietario',
            'quota' => 100,
            'attivo' => true,
            'data_inizio' => now()
        ]);
    }

    // CONTI
    // Amministrative: 3750.00
    // Condominio: 4350.00
    // Centrale Termica: 2350.00
    // Scale: 2100.00
    // Totale Generale = 12550.00
    $contiGenerali = [
        ['nome' => 'Amministrative', 'importo' => 375000],
        ['nome' => 'Condominio', 'importo' => 435000],
        ['nome' => 'Centrale Termica', 'importo' => 235000],
        ['nome' => 'Scale', 'importo' => 210000],
    ];

    foreach ($contiGenerali as $c) {
        $conto = Conto::create([
            'piano_conto_id' => $pianoConto->id,
            'nome' => $c['nome'],
            'tipo' => 'spesa',
            'importo' => $c['importo']
        ]);
        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id' => $conto->id,
            'tabella_id' => $tabellaGen->id,
            'coefficiente' => 100,
            'created_at' => now(), 'updated_at' => now()
        ]);
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivotId,
            'soggetto' => 'proprietario',
            'percentuale' => 100,
            'created_at' => now(), 'updated_at' => now()
        ]);
    }

    // Conto Riscaldamento: 15300.00
    $contoRisc = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome' => "Gas Riscaldamento",
        'tipo' => 'spesa',
        'importo' => 1530000
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $contoRisc->id,
        'tabella_id' => $tabellaRisc->id,
        'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now()
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId,
        'soggetto' => 'proprietario',
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
    
    // Controlliamo il "diminuisce di riga in riga" su Generale
    // Il totale per ogni soggetto su Generale dovrebbe essere ~ 209166 o 209167
    // Ma a causa del bug, i primi avranno di più!
    echo "TOTALE GENERATO PER SOGGETTO IN GENERALE:\n";
    $totGenPerSubj = [];
    foreach ($matrice['righe'] as $iid => $riga) {
        foreach ($riga['soggetti'] as $aid => $sogg) {
            $val = $sogg['per_tabella'][$tabellaGen->id]['importo'];
            echo "Immobile $iid: $val\n";
            $totGenPerSubj[$iid] = $val;
        }
    }

    $riscTotale = $matrice['tot_per_tabella'][$tabellaRisc->id];
    echo "TOTALE RISCALDAMENTO: $riscTotale (dovrebbe essere 1530000)\n";
    
    // Aspettiamoci il fallimento così il test evidenzia il baco
    expect($riscTotale)->toBe(1530000);
});
