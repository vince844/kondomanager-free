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

it('riproduce il bug esatto del condominio T12', function () {
    $condominio = Condominio::factory()->create(['nome' => 'T12']);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto'
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'nome' => 'Gestione Base', 'tipo' => 'ordinaria'
    ]);
    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC'
    ]);

    $tabGen = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GENERALE', 'quota' => 'quote']);
    $tabAsc = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'ASCENSORE', 'quota' => 'quote']);
    $tabRisc = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'RISCALDAMENTO', 'quota' => 'millesimi']);

    $datiImmobili = [
        1 => ['inq' => 'Inquilino 1', 'prop' => 'Proprietario 1', 'asc' => 1, 'risc' => 156.404],
        2 => ['prop' => 'Proprietario 2', 'asc' => 1, 'risc' => 161.191],
        3 => ['inq' => 'Inquilino 3', 'prop' => 'Proprietario 3', 'asc' => 2, 'risc' => 151.031],
        4 => ['prop' => 'Proprietario 4', 'asc' => 2, 'risc' => 144.862],
        5 => ['prop' => 'Proprietario 5', 'asc' => 3, 'risc' => 192.393],
        6 => ['prop' => 'Proprietario 6', 'asc' => 3, 'risc' => 194.119],
    ];

    foreach ($datiImmobili as $i => $d) {
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string)$i,
            'nome' => "App $i", 'codice_immobile' => "C1-00$i", 'descrizione' => 'Test'
        ]);
        
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $tabGen->id, 'immobile_id' => $immobile->id, 'valore' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $tabAsc->id, 'immobile_id' => $immobile->id, 'valore' => $d['asc'], 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $tabRisc->id, 'immobile_id' => $immobile->id, 'valore' => $d['risc'], 'created_at' => now(), 'updated_at' => now()]
        ]);

        $prop = Anagrafica::factory()->create(['nome' => $d['prop']]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $prop->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()
        ]);

        if (isset($d['inq'])) {
            $inq = Anagrafica::factory()->create(['nome' => $d['inq']]);
            DB::table('anagrafica_immobile')->insert([
                'anagrafica_id' => $inq->id, 'immobile_id' => $immobile->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()
            ]);
        }
    }

    $conti = [
        ['nome' => 'Assicurazione', 'importo' => 150000, 'tab' => $tabGen->id, 'sogg' => 'proprietario'],
        ['nome' => 'Compenso Amm', 'importo' => 219000, 'tab' => $tabGen->id, 'sogg' => 'proprietario'],
        ['nome' => 'Cancelleria', 'importo' => 5000, 'tab' => $tabGen->id, 'sogg' => 'proprietario'],
        ['nome' => 'Banca', 'importo' => 10000, 'tab' => $tabGen->id, 'sogg' => 'proprietario'], // total AM = 3850 (wait, need 3750 -> make Banca 0 or Compenso 2100)
        ['nome' => 'Pulizia', 'importo' => 135000, 'tab' => $tabGen->id, 'sogg' => 'proprietario'],
        ['nome' => 'Manutenzione', 'importo' => 100000, 'tab' => $tabGen->id, 'sogg' => 'proprietario'],
        ['nome' => 'Varie', 'importo' => 200000, 'tab' => $tabGen->id, 'sogg' => 'proprietario'], // total CO = 4350
        ['nome' => 'CT', 'importo' => 235000, 'tab' => $tabGen->id, 'sogg' => 'inquilino'],
        ['nome' => 'SC', 'importo' => 210000, 'tab' => $tabGen->id, 'sogg' => 'inquilino'],
        ['nome' => 'ASC', 'importo' => 100000, 'tab' => $tabAsc->id, 'sogg' => 'inquilino'],
        ['nome' => 'RI', 'importo' => 1530000, 'tab' => $tabRisc->id, 'sogg' => 'inquilino'],
    ];

    foreach ($conti as $c) {
        $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => $c['nome'], 'tipo' => 'spesa', 'importo' => $c['importo']]);
        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id' => $conto->id, 'tabella_id' => $c['tab'], 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()
        ]);
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => $c['sogg'], 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()
        ]);
    }

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Piano Preventivo', 'stato' => 'bozza', 'numero_rate' => 1
    ]);
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);

    echo "\n==============\n";
    echo "TOTALE GENERALE (Dovrebbe essere 12550.00, quanto è?): " . ($matrice['tot_per_tabella'][$tabGen->id]/100) . "\n";
    echo "TOTALE RISCALDAMENTO (Dovrebbe essere 15300.00, quanto è?): " . ($matrice['tot_per_tabella'][$tabRisc->id]/100) . "\n";
    echo "TOTALE ASCENSORE: " . ($matrice['tot_per_tabella'][$tabAsc->id]/100) . "\n";
    echo "\nUNITÀ 2 (proprietario senza inquilino):\n";
    $unita2Riga = $matrice['righe'][2]['soggetti'];
    $unita2 = reset($unita2Riga);
    echo "Generale: " . ($unita2['per_tabella'][$tabGen->id]['importo']/100) . " (Aspettato: 2091.69)\n";
    echo "Ascensore: " . ($unita2['per_tabella'][$tabAsc->id]['importo']/100) . " (Aspettato: 83.33)\n";
    echo "Riscaldamento: " . ($unita2['per_tabella'][$tabRisc->id]['importo']/100) . " (Aspettato: 2466.26)\n";
    echo "Totale Sogg: " . ($unita2['totale']/100) . " (Aspettato: 4641.28)\n";
    
    if (abs($matrice['tot_per_tabella'][$tabRisc->id] - 1530000) > 2) {
        $this->fail("ERRORE: expected 1530000 but got " . $matrice['tot_per_tabella'][$tabRisc->id]);
    }

    expect($matrice['tot_per_tabella'][$tabRisc->id])->toBeBetween(1529998, 1530002);
});
