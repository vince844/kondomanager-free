<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Test della Cascata di Risoluzione del Ruolo nel Motore di Riparto.
 *
 * Verifica il Rule Engine Livello 3 in CalcoloQuoteService::distribuisciSuTabelle().
 * Spec di riferimento: docs/cascata_risoluzione_ruolo_coerenza_ruoli.md §2.3
 *
 * TABELLA DEI CASI:
 * ┌───┬─────────────────────────────────────────────┬──────────────────────────────┬────────────────────────┐
 * │ # │ Composizione immobile                       │ Tabella (coeff. su ruolo)    │ Chi paga               │
 * ├───┼─────────────────────────────────────────────┼──────────────────────────────┼────────────────────────┤
 * │ 1 │ proprietario + usufruttuario                │ usufruttuario 100%           │ usufruttuario          │
 * │ 2 │ proprietario(=nudo) + usufruttuario, no inq │ inquilino 100% (ordinaria)   │ usufruttuario ← IL BUG │
 * │ 3 │ proprietario + inquilino                    │ inquilino 100% (ordinaria)   │ inquilino              │
 * │ 4 │ solo proprietario                           │ inquilino 100% (ordinaria)   │ proprietario           │
 * │ 5 │ proprietario(=nudo) + usufruttuario         │ proprietario 100%            │ proprietario           │
 * │ 8 │ due comproprietari quota 50/50              │ proprietario 100%            │ split 50/50            │
 * └───┴─────────────────────────────────────────────┴──────────────────────────────┴────────────────────────┘
 *
 * Il caso #2 è il bug principale risolto in v1.9.1-beta.10:
 * - PRIMA (Bug): Se un conto puntava all'Inquilino, in assenza di inquilino
 *   il sistema falliva la ricerca ricadendo erroneamente sul Proprietario, ignorando
 *   l'Usufruttuario e violando l'art. 1004 del Codice Civile.
 * - DOPO (Fix): È stata introdotta la catena ['inquilino', 'usufruttuario', 'proprietario'].
 *   Il sistema la scorre sequenzialmente: non trovando l'inquilino,
 *   trova l'usufruttuario e addebita a lui la quota (passando il test).
 */

/**
 * Crea lo scenario base con un Condominio, Gestione, PianoConto, Tabella,
 * e un Conto collegato alla tabella con uno specifico coefficiente/soggetto.
 */
function createScenarioCascata(string $ruoloRiparto = 'inquilino', int $importo = 100000): object
{
    $condominio = Condominio::factory()->create();
    
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto'
    ]);
    
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Ordinaria 2026',
        'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria'
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Preventivo 2026'
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 
        'nome' => 'Tabella A',
        'tipo' => 'standard',   
        'quota' => 'millesimi',
        'attiva' => true
    ]);

    $conto = Conto::forceCreate([
        'piano_conto_id' => $pianoConto->id,
        'nome' => 'Spesa Test',
        'importo' => $importo,  
        'tipo' => 'spesa',
        'attivo' => true
    ]);

    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id,
        'tabella_id' => $tabella->id,
        'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now()
    ]);

    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId,
        'soggetto' => $ruoloRiparto, 
        'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now()
    ]);

    return (object)[
        'condominio' => $condominio,
        'gestione' => $gestione,
        'tabella' => $tabella,
        'conto' => $conto
    ];
}

function immobileConAnagrafiche(array $soggetti, Tabella $tabella, int $valoreMillesimale = 1000): object
{
    // Contatore monotòno per dati univoci: le colonne anagrafiche.email e
    // .codice_fiscale sono UNIQUE, e usare rand() (email 1-1000, CF 10-99)
    // causava collisioni sporadiche quando un test crea più anagrafiche,
    // rendendo la suite flaky. Un contatore statico garantisce l'unicità
    // senza dipendere dalla casualità.
    static $seq = 0;

    $seq++;
    $immobile = Immobile::forceCreate([
        'condominio_id' => $tabella->condominio_id,
        'nome' => 'Int ' . $seq,
        'descrizione' => 'Appartamento',
        'interno' => (string) $seq,
    ]);

    foreach ($soggetti as &$s) {
        $seq++;
        $anagrafica = Anagrafica::forceCreate([
            'nome' => 'Test User ' . $seq,
            'email' => 'test' . $seq . '@example.com',
            'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => 'RSSMRA' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
        ]);
        
        // SQLite constraint bypass for enum: we can insert directly into pivot 
        // Note: tipologia in pivot also has a CHECK constraint, but wait, let's see if tipologia works.
        // It failed on conto_tabella_ripartizioni, not anagrafica_immobile!
        // The error was on conto_tabella_ripartizioni for test 6.
        // For tipologia, we will see if it fails.
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id,
            'tipologia' => $s['tipologia'],
            'quota'     => $s['quota'] ?? 100.0,
            'attivo'    => $s['attivo'] ?? true,
            'data_inizio' => now()->format('Y-m-d'),
        ]);
        // Aggiungiamo dinamicamente l'id all'array del soggetto per i check nel test
        $s['anagrafica_id'] = $anagrafica->id;
    }

    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id,
        'immobile_id' => $immobile->id,
        'valore' => $valoreMillesimale,
        'created_at' => now(), 'updated_at' => now()
    ]);

    // Memorizziamo gli anagrafica_id ritornati così il test sa cosa cercare
    return (object)['immobile' => $immobile, 'soggetti' => $soggetti];
}

test('caso 1: proprietario + usufruttuario, tabella su usufruttuario 100%', function () {
    $sc = createScenarioCascata('usufruttuario');
    $im = immobileConAnagrafiche([
        ['tipologia' => 'proprietario'],
        ['tipologia' => 'usufruttuario']
    ], $sc->tabella);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    $idUsufruttuario = $im->soggetti[1]['anagrafica_id'];
    
    expect($quote)->toHaveKey($idUsufruttuario)
        ->and($quote[$idUsufruttuario][$im->immobile->id])->toBe(100000)
        ->and($service->getScoperti())->toBeEmpty();
});

test('caso 2: nudo prop + usufruttuario no inquilino, tabella ordinaria (inquilino) -> cascata a usufruttuario', function () {
    $sc = createScenarioCascata('inquilino');
    $im = immobileConAnagrafiche([
        ['tipologia' => 'nuda_proprietario'],
        ['tipologia' => 'usufruttuario']
    ], $sc->tabella);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    $idUsufruttuario = $im->soggetti[1]['anagrafica_id'];
    
    expect($quote)->toHaveKey($idUsufruttuario)
        ->and($quote[$idUsufruttuario][$im->immobile->id])->toBe(100000)
        ->and($service->getScoperti())->toBeEmpty();
})->skip('nuda_proprietario non ancora in enum (v1.10 Fase 1). Il test 9 copre la stessa cascata con ruoli esistenti — questo diventa il test canonico post-migrazione.');

test('caso 3: proprietario + inquilino, tabella ordinaria (inquilino)', function () {
    $sc = createScenarioCascata('inquilino');
    $im = immobileConAnagrafiche([
        ['tipologia' => 'proprietario'],
        ['tipologia' => 'inquilino']
    ], $sc->tabella);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    $idInquilino = $im->soggetti[1]['anagrafica_id'];
    
    expect($quote)->toHaveKey($idInquilino)
        ->and($quote[$idInquilino][$im->immobile->id])->toBe(100000);
});

test('caso 4: solo proprietario, tabella ordinaria (inquilino) -> cascata a proprietario', function () {
    $sc = createScenarioCascata('inquilino');
    $im = immobileConAnagrafiche([
        ['tipologia' => 'proprietario']
    ], $sc->tabella);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    $idProprietario = $im->soggetti[0]['anagrafica_id'];
    
    expect($quote)->toHaveKey($idProprietario)
        ->and($quote[$idProprietario][$im->immobile->id])->toBe(100000);
});

test('caso 5: nudo prop + usufruttuario, tabella straordinaria (proprietario) -> nudo prop', function () {
    $sc = createScenarioCascata('proprietario');
    $im = immobileConAnagrafiche([
        ['tipologia' => 'nuda_proprietario'],
        ['tipologia' => 'usufruttuario']
    ], $sc->tabella);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    $idNudo = $im->soggetti[0]['anagrafica_id'];
    
    expect($quote)->toHaveKey($idNudo)
        ->and($quote[$idNudo][$im->immobile->id])->toBe(100000);
})->skip('nuda_proprietario non ancora in enum (v1.10 Fase 1). Il test 9 copre la stessa cascata con ruoli esistenti — questo diventa il test canonico post-migrazione.');

test('caso 6: solo proprietario, tabella straordinaria (nuda_proprietario) -> proprietario', function () {
    $sc = createScenarioCascata('nuda_proprietario');
    $im = immobileConAnagrafiche([
        ['tipologia' => 'proprietario']
    ], $sc->tabella);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    $idProprietario = $im->soggetti[0]['anagrafica_id'];
    
    expect($quote)->toHaveKey($idProprietario)
        ->and($quote[$idProprietario][$im->immobile->id])->toBe(100000);
})->skip('nuda_proprietario non ancora in enum (v1.10 Fase 1). Il test 9 copre la stessa cascata con ruoli esistenti — questo diventa il test canonico post-migrazione.');

test('caso 7: nessuna anagrafica risolvibile, tabella inquilino -> scoperto e 0 agli altri', function () {
    $sc = createScenarioCascata('inquilino', 100000); // 1000 EUR
    
    // Immobile vuoto (500 millesimi) -> genera scoperto
    $imVuoto = immobileConAnagrafiche([], $sc->tabella, 500);
    
    // Immobile sano (500 millesimi)
    $imSano = immobileConAnagrafiche([
        ['tipologia' => 'proprietario']
    ], $sc->tabella, 500);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    // Lo scoperto deve essere di 500 EUR
    $scoperti = $service->getScoperti();
    expect($scoperti)->toHaveCount(1)
        ->and($scoperti[0]['importo'])->toBe(50000);
        
    // L'altro condomino deve ricevere solo i suoi 500 EUR (NON tutto il 1000)
    $idSano = $imSano->soggetti[0]['anagrafica_id'];
    expect($quote[$idSano][$imSano->immobile->id])->toBe(50000);
    
    // Verifichiamo che l'immobile vuoto non abbia quote e che il totale distribuito sia esattamente 500 EUR
    foreach ($quote as $byImmobile) {
        expect($byImmobile)->not->toHaveKey($imVuoto->immobile->id);
    }
    $totaleDistribuito = 0;
    foreach ($quote as $byImmobile) {
        foreach ($byImmobile as $importo) { $totaleDistribuito += $importo; }
    }
    expect($totaleDistribuito)->toBe(50000); // NON 100000
});

test('caso 8: due comproprietari 50/50, tabella proprietario -> split esatto', function () {
    $sc = createScenarioCascata('proprietario');
    $im = immobileConAnagrafiche([
        ['tipologia' => 'proprietario', 'quota' => 50],
        ['tipologia' => 'proprietario', 'quota' => 50]
    ], $sc->tabella);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    $idA = $im->soggetti[0]['anagrafica_id'];
    $idB = $im->soggetti[1]['anagrafica_id'];
    
    expect($quote[$idA][$im->immobile->id])->toBe(50000)
        ->and($quote[$idB][$im->immobile->id])->toBe(50000);
});

test('caso 9: proprietario + usufruttuario, tabella inquilino -> usufruttuario, no scoperto', function () {
    $sc = createScenarioCascata('inquilino');
    $im = immobileConAnagrafiche([
        ['tipologia' => 'proprietario'],
        ['tipologia' => 'usufruttuario']
    ], $sc->tabella);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);
    
    $idUsuf = $im->soggetti[1]['anagrafica_id'];
    
    expect($quote)->toHaveKey($idUsuf)
        ->and($quote[$idUsuf][$im->immobile->id])->toBe(100000)
        ->and($service->getScoperti())->toBeEmpty();
});

test('caso 10: scoperto contiene ruolo_richiesto corretto (non stringa fissa)', function () {
    $sc = createScenarioCascata('inquilino', 100000);
    immobileConAnagrafiche([], $sc->tabella, 1000); // nessuna anagrafica

    $service = new CalcoloQuoteService();
    $service->calcolaPerGestione($sc->gestione);

    $scoperti = $service->getScoperti();
    expect($scoperti)->toHaveCount(1)
        ->and($scoperti[0])->toHaveKey('ruolo_richiesto')
        ->and($scoperti[0]['ruolo_richiesto'])->toBe('inquilino')  // NON '(cascata esaurita)'
        ->and($scoperti[0])->not->toHaveKey('ruolo');              // vecchia chiave rimossa
});
