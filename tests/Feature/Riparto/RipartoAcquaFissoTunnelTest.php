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

/**
 * Riproduce il pattern segnalato dall'amministratore sul condominio PAR:
 * ACQUA FISSO è una quota fissa (1 per unità) su 21 unità con un importo che
 * non si divide esattamente. SCALA e GENERALE invece si dividono in modo
 * perfettamente esatto (nessun resto proprio) su 20 unità uniformi.
 *
 * Col vecchio algoritmo ("l'ultima tabella per peso assoluto assorbe tutto lo
 * scarto"), SCALA — dominante e senza alcun bisogno di arrotondamento — viene
 * comunque scelta come "ultima" e finisce per assorbire lo scarto generato da
 * ACQUA FISSO, risultando in un totale di colonna SBAGLIATO (non torna con la
 * propria divisione esatta). Con Hamilton (metodo del resto più grande),
 * SCALA resta sempre esatta e la fluttuazione resta confinata in ACQUA FISSO,
 * dove appartiene strutturalmente. In entrambi i casi il TOTALE SOGG. per
 * condomino resta identico (garanzia legale mai toccata).
 */
it('mantiene SCALA esatta e confina la fluttuazione in ACQUA FISSO, senza corromperne il totale di colonna', function () {
    $condominio = Condominio::factory()->create(['nome' => 'PAR-TEST']);
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

    $tabScala = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'SCALA', 'quota' => 'millesimi']);
    $tabGenerale = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GENERALE', 'quota' => 'millesimi']);
    $tabAcquaFisso = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'ACQUA FISSO', 'quota' => 'quote']);
    $tabTunnel = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'TUNNEL', 'quota' => 'millesimi']);

    $immobili = [];

    // 20 unità "normali": SCALA (2000 mill. uniformi) + GENERALE (1000 mill.
    // uniformi) + ACQUA FISSO (quota 1). SCALA e GENERALE dividono in modo
    // esatto — solo ACQUA FISSO ha un resto genuino.
    for ($i = 1; $i <= 20; $i++) {
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $i,
            'nome' => "App $i", 'codice_immobile' => "PAR-$i", 'descrizione' => 'Test'
        ]);
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $tabScala->id, 'immobile_id' => $immobile->id, 'valore' => 2000, 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $tabGenerale->id, 'immobile_id' => $immobile->id, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $tabAcquaFisso->id, 'immobile_id' => $immobile->id, 'valore' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $prop = Anagrafica::factory()->create(['nome' => "Proprietario $i"]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $prop->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()
        ]);
        $immobili[$i] = $immobile;
    }

    // Unità 21 "speciale": NIENTE Generale/Scala, solo ACQUA FISSO (quota 1) + TUNNEL (unico partecipante)
    $immobile21 = Immobile::create([
        'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '21',
        'nome' => 'App 21', 'codice_immobile' => 'PAR-21', 'descrizione' => 'Test'
    ]);
    DB::table('quote_tabella')->insert([
        ['tabella_id' => $tabAcquaFisso->id, 'immobile_id' => $immobile21->id, 'valore' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['tabella_id' => $tabTunnel->id, 'immobile_id' => $immobile21->id, 'valore' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $prop21 = Anagrafica::factory()->create(['nome' => 'Proprietario 21']);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $prop21->id, 'immobile_id' => $immobile21->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()
    ]);
    $immobili[21] = $immobile21;

    // CONTI
    $contiDef = [
        ['nome' => 'Scala',          'importo' => 4000000, 'tab' => $tabScala->id],    // 20 x 2000 mill = 200000 cents cad, esatto
        ['nome' => 'Spese Generali', 'importo' => 2000000, 'tab' => $tabGenerale->id], // 20 x 1000 mill = 100000 cents cad, esatto
        ['nome' => 'Acqua Fisso',    'importo' => 220007,  'tab' => $tabAcquaFisso->id], // 21 quote da 1 -> 10476,52 cad, resto genuino
        ['nome' => 'Tunnel',         'importo' => 20000,   'tab' => $tabTunnel->id],     // 1 sola quota -> 20000 esatto
    ];

    foreach ($contiDef as $c) {
        $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => $c['nome'], 'tipo' => 'spesa', 'importo' => $c['importo']]);
        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id' => $conto->id, 'tabella_id' => $c['tab'], 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()
        ]);
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()
        ]);
    }

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Piano Preventivo', 'stato' => 'bozza', 'numero_rate' => 1
    ]);
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);

    // SCALA e GENERALE si dividono in modo esatto su 20 unità uniformi: il loro
    // totale di colonna DEVE tornare esatto, senza alcuna tolleranza — non hanno
    // nulla a che fare con l'indivisibilità di ACQUA FISSO.
    expect($matrice['tot_per_tabella'][$tabScala->id])->toBe(4000000);
    expect($matrice['tot_per_tabella'][$tabGenerale->id])->toBe(2000000);

    // ACQUA FISSO ha un resto genuino: il totale di colonna deve tornare
    // esattamente al budget del conto (220007), non un valore diverso "gonfiato"
    // per compensare artefatti di altre tabelle.
    expect($matrice['tot_per_tabella'][$tabAcquaFisso->id])->toBe(220007);

    // Il totale complessivo (cifra legale aggregata) non deve mai cambiare in
    // base all'algoritmo di distribuzione tra colonne.
    expect($matrice['gran_totale'])->toBe(4000000 + 2000000 + 220007 + 20000);
});
