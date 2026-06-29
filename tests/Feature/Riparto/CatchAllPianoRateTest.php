<?php

/**
 * CatchAllPianoRateTest
 *
 * Verifica la logica "catch-all" dei piani rate.
 *
 * SCENARIO
 * --------
 * Una gestione ha 2 capitoli radice nel piano dei conti:
 *   • Capitolo1 — assegnato esplicitamente a un piano parziale attivo.
 *   • Capitolo2 — orfano: nessun piano lo rivendica.
 *
 * Il piano catch-all (nessun capitolo esplicito) deve processare SOLO il
 * Capitolo2; il Capitolo1 è già "impegnato" e non va contato due volte.
 *
 * COSA SI VERIFICA
 * ----------------
 * 1. La query SQL che individua i capitoli "impegnati" da altri piani attivi.
 * 2. RipartoCapitoliService → buildMatrice() esclude i capitoli impegnati.
 * 3. CalcoloQuoteService → calcolaPerGestione() distribuisce solo gli orfani.
 * 4. Invariante architetturale: parziale + catch-all == intera gestione.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use App\Services\RipartoCapitoliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// =============================================================================
// SETUP CONDIVISO
// =============================================================================

beforeEach(function () {

    // --- Struttura condominiale ---
    $this->condominio = Condominio::factory()->create();

    $this->gestione = Gestione::factory()->create([
        'condominio_id' => $this->condominio->id,
    ]);

    $this->pianoConto = PianoConto::create([
        'condominio_id' => $this->condominio->id,
        'gestione_id'   => $this->gestione->id,
        'nome'          => 'Piano dei Conti Test',
    ]);

    // --- Capitoli radice ---
    // Capitolo1: verrà assegnato al piano parziale (deve essere ESCLUSO dal catch-all)
    $this->capitolo1 = Conto::create([
        'piano_conto_id' => $this->pianoConto->id,
        'nome'           => 'Pulizie Scale',
        'tipo'           => 'spesa',
        'importo'        => 120_000, // 1.200,00 EUR in centesimi
    ]);

    // Capitolo2: orfano → spetta SOLO al catch-all
    $this->capitolo2 = Conto::create([
        'piano_conto_id' => $this->pianoConto->id,
        'nome'           => 'Assicurazione Fabbricato',
        'tipo'           => 'spesa',
        'importo'        => 60_000, //   600,00 EUR in centesimi
    ]);

    // --- Infrastruttura millesimale ---
    // 1 tabella · 1 immobile · 1 anagrafica proprietario (quota 100%)
    // Con un solo soggetto il Metodo di Hare è triviale: nessuna perdita di centesimi.
    $tabella = Tabella::create([
        'condominio_id' => $this->condominio->id,
        'nome'          => 'Tabella Generale',
        'tipo'          => 'standard',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    $immobile = Immobile::create([
        'condominio_id'  => $this->condominio->id,
        'codice_immobile'=> 'TEST-001',
        'nome'           => 'Appartamento A1',
        'interno'        => '1',
        'descrizione'    => 'Piano primo',
    ]);
    $this->immobileId = $immobile->id;

    $this->anagrafica = Anagrafica::factory()->create();

    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $this->anagrafica->id,
        'immobile_id'   => $immobile->id,
        'tipologia'     => 'proprietario',
        'quota'         => 100,
        'attivo'        => true,
        'data_inizio'   => now()->subYear()->toDateString(),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('quote_tabella')->insert([
        'tabella_id'  => $tabella->id,
        'immobile_id' => $immobile->id,
        'valore'      => 1000.0, // 1000 millesimi (proprietà intera)
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    // Entrambi i capitoli usano la stessa tabella con coefficiente 100%
    foreach ([$this->capitolo1->id, $this->capitolo2->id] as $contoId) {
        DB::table('conto_tabella_millesimale')->insert([
            'conto_id'    => $contoId,
            'tabella_id'  => $tabella->id,
            'coefficiente'=> 100,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // --- Piani rate ---
    // piani_rate.attivo ha DEFAULT true nel DB, ma lo passiamo esplicitamente
    // per chiarire l'intento del test.

    // Piano parziale: assegna esplicitamente SOLO Capitolo1
    $this->partialPlan = PianoRate::create([
        'gestione_id'   => $this->gestione->id,
        'condominio_id' => $this->condominio->id,
        'nome'          => 'Piano Parziale Scale',
        'numero_rate'   => 12,
        'stato'         => 'bozza',
        'attivo'        => true,
    ]);
    $this->partialPlan->capitoli()->attach($this->capitolo1->id);

    // Piano catch-all: nessun capitolo esplicito → raccoglitore degli orfani
    $this->catchAllPlan = PianoRate::create([
        'gestione_id'   => $this->gestione->id,
        'condominio_id' => $this->condominio->id,
        'nome'          => 'Piano Catch-All',
        'numero_rate'   => 12,
        'stato'         => 'bozza',
        'attivo'        => true,
    ]);
    // Nessun attach: il piano non ha capitoli espliciti
});

// =============================================================================
// GRUPPO 1 — Query di esclusione (puro SQL, indipendente dai servizi)
//
// Verifica che la query usata internamente da entrambi i servizi individui
// correttamente i capitoli "impegnati" da altri piani attivi nella stessa
// gestione. Se questa query è sbagliata, tutto il resto è inutile.
// =============================================================================

describe('Query di esclusione capitoli impegnati', function () {

    it('individua il capitolo assegnato al piano parziale come impegnato', function () {
        $committed = DB::table('piano_rate_capitoli')
            ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
            ->where('piani_rate.gestione_id', $this->gestione->id)
            ->where('piani_rate.attivo', true)
            ->where('piani_rate.id', '!=', $this->catchAllPlan->id)
            ->pluck('conto_id')
            ->toArray();

        expect($committed)
            ->toContain($this->capitolo1->id)
            ->not->toContain($this->capitolo2->id);
    });

    it('non considera impegnati i capitoli di un piano inattivo', function () {
        // Un piano disattivato NON deve "bloccare" i suoi capitoli
        $inactivePlan = PianoRate::create([
            'gestione_id'   => $this->gestione->id,
            'condominio_id' => $this->condominio->id,
            'nome'          => 'Piano Inattivo',
            'numero_rate'   => 12,
            'stato'         => 'bozza',
            'attivo'        => false,
        ]);
        $inactivePlan->capitoli()->attach($this->capitolo2->id);

        $committed = DB::table('piano_rate_capitoli')
            ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
            ->where('piani_rate.gestione_id', $this->gestione->id)
            ->where('piani_rate.attivo', true)
            ->where('piani_rate.id', '!=', $this->catchAllPlan->id)
            ->pluck('conto_id')
            ->toArray();

        expect($committed)->not->toContain($this->capitolo2->id);
    });

});

// =============================================================================
// GRUPPO 2 — RipartoCapitoliService (matrice per la stampa PDF)
// =============================================================================

describe('RipartoCapitoliService › buildMatrice catch-all', function () {

    it('include solo il capitolo orfano nella sezione capitoli', function () {
        $matrice = app(RipartoCapitoliService::class)->buildMatrice($this->catchAllPlan);

        expect($matrice['capitoli'])
            ->toHaveKey($this->capitolo2->id)
            ->not->toHaveKey($this->capitolo1->id);
    });

    it('ha gran_totale pari al solo importo del capitolo orfano', function () {
        $matrice = app(RipartoCapitoliService::class)->buildMatrice($this->catchAllPlan);

        expect($matrice['gran_totale'])->toBe(60_000);
    });

    it('tot_per_capitolo contiene solo il capitolo orfano con importo corretto', function () {
        $matrice = app(RipartoCapitoliService::class)->buildMatrice($this->catchAllPlan);

        expect($matrice['tot_per_capitolo'])
            ->toHaveKey($this->capitolo2->id)
            ->not->toHaveKey($this->capitolo1->id);

        expect($matrice['tot_per_capitolo'][$this->capitolo2->id])->toBe(60_000);
    });

});

describe('RipartoCapitoliService › buildMatrice piano parziale', function () {

    it('include solo il capitolo assegnato esplicitamente', function () {
        $matrice = app(RipartoCapitoliService::class)->buildMatrice($this->partialPlan);

        expect($matrice['capitoli'])
            ->toHaveKey($this->capitolo1->id)
            ->not->toHaveKey($this->capitolo2->id);
    });

    it('ha gran_totale pari al solo importo del capitolo assegnato', function () {
        $matrice = app(RipartoCapitoliService::class)->buildMatrice($this->partialPlan);

        expect($matrice['gran_totale'])->toBe(120_000);
    });

});

// =============================================================================
// GRUPPO 3 — CalcoloQuoteService (generazione delle quote per le rate)
// =============================================================================

describe('CalcoloQuoteService › calcolaPerGestione catch-all', function () {

    it('distribuisce solo l\'importo del capitolo orfano', function () {
        $totali = app(CalcoloQuoteService::class)
            ->calcolaPerGestione($this->gestione, $this->catchAllPlan);

        $totaleDistribuito = collect($totali)
            ->flatMap(fn(array $immobili) => array_values($immobili))
            ->sum();

        expect($totaleDistribuito)->toBe(60_000);
    });

    it('non include l\'importo del capitolo già coperto dal piano parziale', function () {
        $totali = app(CalcoloQuoteService::class)
            ->calcolaPerGestione($this->gestione, $this->catchAllPlan);

        $totaleDistribuito = collect($totali)
            ->flatMap(fn(array $immobili) => array_values($immobili))
            ->sum();

        // Se il catch-all includesse anche Capitolo1, il totale sarebbe 180.000
        expect($totaleDistribuito)->not->toBe(120_000 + 60_000);
    });

    it('attribuisce tutto l\'importo all\'unica anagrafica presente (Hare triviale)', function () {
        $totali = app(CalcoloQuoteService::class)
            ->calcolaPerGestione($this->gestione, $this->catchAllPlan);

        expect($totali)->toHaveKey($this->anagrafica->id);
        expect($totali[$this->anagrafica->id])->toHaveKey($this->immobileId);
        expect($totali[$this->anagrafica->id][$this->immobileId])->toBe(60_000);
    });

});

describe('CalcoloQuoteService › calcolaPerGestione piano parziale', function () {

    it('distribuisce solo l\'importo del capitolo assegnato', function () {
        $totali = app(CalcoloQuoteService::class)
            ->calcolaPerGestione($this->gestione, $this->partialPlan);

        $totaleDistribuito = collect($totali)
            ->flatMap(fn(array $immobili) => array_values($immobili))
            ->sum();

        expect($totaleDistribuito)->toBe(120_000);
    });

});

// =============================================================================
// INVARIANTE ARCHITETTURALE
//
// Parziale + catch-all devono coprire l'intera gestione:
//   • nessun euro perso (buchi)
//   • nessun euro doppio (sovrapposizioni)
// Questo test cristallizza la garanzia che qualsiasi refactor futuro non
// spezzi la proprietà fondamentale del motore di riparto.
// =============================================================================

it('parziale + catch-all coprono l\'intera gestione senza buchi né doppioni', function () {
    $totParziale = collect(
            app(CalcoloQuoteService::class)->calcolaPerGestione($this->gestione, $this->partialPlan)
        )
        ->flatMap(fn(array $i) => array_values($i))
        ->sum();

    // Istanza fresca per evitare qualsiasi stato residuo tra le due chiamate
    $totCatchAll = collect(
            app(CalcoloQuoteService::class)->calcolaPerGestione($this->gestione, $this->catchAllPlan)
        )
        ->flatMap(fn(array $i) => array_values($i))
        ->sum();

    expect($totParziale + $totCatchAll)->toBe(120_000 + 60_000);
});
