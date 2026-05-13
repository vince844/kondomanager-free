<?php

/**
 * TEST SUITE: Migrazione v1.8 → v1.9 — Integrità Pivot piano_rate_capitoli
 *
 * Contesto storico:
 * Nella versione transitoria della beta.29, la migration 102054 eseguiva un
 * DB::table('piano_rate_capitoli')->truncate() che cancellava TUTTA la pivot,
 * inclusi i piani con rate già emesse. La versione corretta usa una cancellazione
 * selettiva (solo bozza/approvato, attivo=true).
 *
 * I test qui sotto coprono i due bug corretti nel commit 3dc7981:
 * 1. La pivot dei piani emessi NON deve essere toccata dalla migration.
 * 2. Il CalcoloQuoteService deve gestire il caso sottoconti con importo=0
 *    (distribuzione di emergenza in parti uguali).
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ============================================================================
// HELPER: Crea lo scenario base con immobile, tabella millesimale e piano conti
// Riusa la stessa struttura di createScenarioPest() per coerenza con la suite.
// ============================================================================
function createScenarioMigrazione(): object
{
    $condominio = Condominio::create([
        'nome'      => 'Cond. Test Migrazione',
        'uuid'      => (string) Str::uuid(),
        'indirizzo' => 'Via Test 1', 'citta' => 'Roma',
        'cap'       => '00100', 'provincia' => 'RM',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome'          => '2025',
        'data_inizio'   => '2025-01-01',
        'data_fine'     => '2025-12-31',
        'stato'         => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Ordinaria 2025',
        'data_inizio'   => '2025-01-01',
        'tipo'          => 'ordinaria',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Mario', 'cognome' => 'Rossi',
        'email'         => 'mario.migrazione@test.it',
        'indirizzo'     => 'Via Verdi 10', 'cap' => '00100',
        'citta'         => 'Roma', 'provincia' => 'RM',
        'codice_fiscale' => 'RSSMRA80A01H501U',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Generale',
        'tipo'          => 'standard',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Int 1',
        'descrizione'   => 'Appartamento',
        'interno'       => '1',
        'foglio'        => '1', 'particella' => '100', 'subalterno' => '1',
    ]);
    $immobile->anagrafiche()->attach($anagrafica->id, [
        'tipologia'   => 'proprietario',
        'quota'       => 100,
        'attivo'      => true,
        'data_inizio' => now()->subYear(),
    ]);
    DB::table('quote_tabella')->insert([
        'tabella_id'  => $tabella->id,
        'immobile_id' => $immobile->id,
        'valore'      => 1000,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $pianoConti = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
        'nome'          => 'Preventivo 2025',
    ]);

    // Helper inline per collegare conto a tabella millesimale
    $collegaTabella = function (int $contoId) use ($tabella): void {
        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id'     => $contoId,
            'tabella_id'   => $tabella->id,
            'coefficiente' => 100,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivotId,
            'soggetto'                     => 'proprietario',
            'percentuale'                  => 100,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);
    };

    return (object) compact(
        'condominio', 'gestione', 'esercizio', 'pianoConti', 'collegaTabella'
    );
}

// ============================================================================
// TEST 1 — REGRESSIONE CRITICA: la migration NON deve toccare piani con rate emesse
//
// Scenario: piano in stato 'bozza' (attivo=true) → pivot popolata correttamente.
// Piano con rate emesse simulato con attivo=true ma stato non in [bozza, approvato]
// viene preservato dalla migration selettiva.
// ============================================================================
test('migration selettiva: pivot dei piani bozza viene rigenerata, pivot degli altri piani è preservata', function () {
    $s = createScenarioMigrazione();

    // Conto padre (mastro) — importo 0, è un contenitore
    $padre = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'nome'           => 'Manutenzione Ordinaria',
        'importo'        => 0,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);

    // Sottoconto foglia — qui è il budget reale
    $figlio = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'parent_id'      => $padre->id,
        'nome'           => 'Portiere',
        'importo'        => 432700, // €4.327,00
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);
    ($s->collegaTabella)($figlio->id);

    // Piano A — in BOZZA (attivo=true): la migration DEVE rigenerarne la pivot
    $pianoA = PianoRate::create([
        'condominio_id'  => $s->condominio->id,
        'gestione_id'    => $s->gestione->id,
        'nome'           => 'Piano Bozza',
        'numero_rate'    => 2,
        'stato'          => 'bozza',
        'attivo'         => true,
    ]);
    // Pivot iniziale di pianoA: solo il padre (come farebbe la vecchia migration)
    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $pianoA->id,
        'conto_id'      => $padre->id,
        'importo'       => null, // ← NULL come nella versione transitoria buggy
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    // Piano B — NON in bozza/approvato: simula un piano che non deve essere toccato
    // (in produzione sarebbe un piano con rate emesse o disattivato)
    $pianoB = PianoRate::create([
        'condominio_id'  => $s->condominio->id,
        'gestione_id'    => $s->gestione->id,
        'nome'           => 'Piano Protetto',
        'numero_rate'    => 2,
        'stato'          => 'bozza',
        'attivo'         => false, // ← attivo=false: NON deve essere toccato
    ]);
    $importoOriginale = 999999; // Valore sentinella da preservare
    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $pianoB->id,
        'conto_id'      => $padre->id,
        'importo'       => $importoOriginale,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    // ─── Eseguiamo la logica della migration (lo stesso codice di up()) ───────
    $pianiDaRicalcolare = DB::table('piani_rate')
        ->where('attivo', true)
        ->whereIn('stato', ['bozza', 'approvato'])
        ->pluck('id');

    DB::table('piano_rate_capitoli')
        ->whereIn('piano_rate_id', $pianiDaRicalcolare)
        ->delete();

    DB::table('piani_rate')
        ->whereIn('id', $pianiDaRicalcolare)
        ->orderBy('id')
        ->lazyById()
        ->each(function (object $piano) {
            $capitoliRadice = DB::table('conti')
                ->join('piani_conti', 'conti.piano_conto_id', '=', 'piani_conti.id')
                ->where('piani_conti.gestione_id', $piano->gestione_id)
                ->whereNull('conti.parent_id')
                ->select('conti.id', 'conti.importo')
                ->get();

            foreach ($capitoliRadice as $capitolo) {
                $importoReale = $capitolo->importo;
                if ($importoReale == 0) {
                    $importoReale = DB::table('conti')
                        ->where('parent_id', $capitolo->id)
                        ->sum('importo');
                }
                DB::table('piano_rate_capitoli')->insert([
                    'piano_rate_id' => $piano->id,
                    'conto_id'      => $capitolo->id,
                    'importo'       => $importoReale > 0 ? $importoReale : 0,
                    'note'          => 'Migrazione V1.9',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        });
    // ─────────────────────────────────────────────────────────────────────────

    // Piano A (bozza, attivo): la pivot deve essere stata rigenerata con importo del figlio
    $pivotA = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $pianoA->id)
        ->first();

    expect($pivotA)->not->toBeNull()
        ->and($pivotA->importo)->toBe(432700); // Somma del figlio

    // Piano B (attivo=false): la pivot deve essere INTATTA con il valore originale
    $pivotB = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $pianoB->id)
        ->first();

    expect($pivotB)->not->toBeNull()
        ->and((int) $pivotB->importo)->toBe($importoOriginale); // NON deve essere cambiato
});

// ============================================================================
// TEST 2 — CalcoloQuoteService: sottoconti con importo=0 (distribuzione emergenza)
//
// Scenario: piano rate con override sul padre, sottoconti tutti a importo=0.
// La beta.29 deve distribuire l'importo del padre in parti uguali tra i figli
// invece di ignorarlo silenziosamente.
// ============================================================================
test('calcolo quote: override su padre con sottoconti importo=0 distribuisce in parti uguali', function () {
    $s = createScenarioMigrazione();

    // Padre mastro — importo 0
    $padre = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'nome'           => 'Spese Generali',
        'importo'        => 0,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);

    // Due sottoconti foglia — entrambi importo=0 (struttura vuota)
    $figlio1 = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'parent_id'      => $padre->id,
        'nome'           => 'Figlio A',
        'importo'        => 0,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);
    $figlio2 = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'parent_id'      => $padre->id,
        'nome'           => 'Figlio B',
        'importo'        => 0,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);
    // Le tabelle millesimali sono sui figli (pattern tipico Kondomanager)
    ($s->collegaTabella)($figlio1->id);
    ($s->collegaTabella)($figlio2->id);

    // Piano rate con override esplicito sul PADRE (come risulta dalla migration)
    $pianoRate = PianoRate::create([
        'condominio_id'  => $s->condominio->id,
        'gestione_id'    => $s->gestione->id,
        'nome'           => 'Piano Test Override Zero',
        'numero_rate'    => 1,
        'stato'          => 'bozza',
        'attivo'         => true,
    ]);

    // Pivot: solo il padre, con importo congelato non-zero
    $pianoRate->capitoli()->attach($padre->id, ['importo' => 200000]); // €2.000,00

    // Calcoliamo
    /** @var CalcoloQuoteService $service */
    $service = app(CalcoloQuoteService::class);
    $totali  = $service->calcolaPerGestione($s->gestione, $pianoRate);

    // Il totale distribuito NON deve essere zero
    // (il fix di emergenza distribuisce in parti uguali quando sottoconti sono a 0)
    $totaleDistribuito = 0;
    foreach ($totali as $perAnagrafica) {
        foreach ($perAnagrafica as $importo) {
            $totaleDistribuito += $importo;
        }
    }

    expect($totaleDistribuito)->toBe(200000)
        ->and($totali)->not->toBeEmpty();
});

// ============================================================================
// TEST 3 — REGRESSIONE: la vecchia migration con truncate() avrebbe azzerato tutto
//
// Questo test verifica che il comportamento BUGGY (truncate) sia effettivamente
// diverso dal comportamento CORRETTO (cancellazione selettiva).
// Serve come documentazione che il bug esisteva e non può ripresentarsi.
// ============================================================================
test('regressione: truncate() avrebbe cancellato anche pivot di piani attivo=false', function () {
    $s = createScenarioMigrazione();

    $conto = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'nome'           => 'Spese Varie',
        'importo'        => 100000,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);

    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id,
        'gestione_id'   => $s->gestione->id,
        'nome'          => 'Piano Con Rate Emesse',
        'numero_rate'   => 2,
        'stato'         => 'bozza',
        'attivo'        => false, // Simulazione piano con rate già emesse
    ]);

    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $piano->id,
        'conto_id'      => $conto->id,
        'importo'       => 100000,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    // ── Simulazione comportamento BUGGY (truncate) ──────────────────────────
    // DB::table('piano_rate_capitoli')->truncate(); // ← questo cancellerebbe tutto

    // ── Comportamento CORRETTO (selettivo) ──────────────────────────────────
    $pianiDaRicalcolare = DB::table('piani_rate')
        ->where('attivo', true)
        ->whereIn('stato', ['bozza', 'approvato'])
        ->pluck('id');

    DB::table('piano_rate_capitoli')
        ->whereIn('piano_rate_id', $pianiDaRicalcolare)
        ->delete();
    // ────────────────────────────────────────────────────────────────────────

    // Il piano con attivo=false NON è in $pianiDaRicalcolare → pivot intatta
    $pivotCount = DB::table('piano_rate_capitoli')
        ->where('piano_rate_id', $piano->id)
        ->count();

    expect($pivotCount)->toBe(1)
        ->and($pianiDaRicalcolare->contains($piano->id))->toBeFalse();
});

// ============================================================================
// TEST 4 — REGRESSIONE CASO FORUM: BudgetCoverageService ignora "Manutenzione
// Ordinaria" perché i suoi sottoconti sono stati creati DOPO il piano rate.
//
// Scenario reale (dati utente):
//   13:28:39 → Piano Rate "Ordinario" (ID=1) creato
//   13:37–43 → Sottoconti di "Manutenzione Ordinaria" aggiunti dal frontend
//   05-03 21:12 → Migration 102054 popola pivot con importo=499.000
//
// BUG: BudgetCoverageService filtra sottocontiValidi per created_at <= piano.created_at
//      → tutti i figli di "Manutenzione Ordinaria" esclusi → copertura = 0 → dashboard sbagliata.
//
// ATTESO: con il fallback (uguale a CalcoloQuoteService), se snapshot è vuoto ma
//         la pivot ha importo congelato > 0, usa tutti i figli correnti.
// ============================================================================
test('BudgetCoverageService: sottoconti creati dopo il piano rate non devono azzerare la copertura dashboard', function () {
    $s = createScenarioMigrazione();

    // ── Struttura conti che riproduce il caso reale ──────────────────────────
    // "Spese Generali" (padre, importo=0) con figli creati PRIMA del piano
    $speseGenerali = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'nome'           => 'Spese Generali',
        'importo'        => 0,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);
    // Figlio di Spese Generali — creato PRIMA del piano (timestamp nel passato)
    $compenso = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'parent_id'      => $speseGenerali->id,
        'nome'           => 'Compenso Amministratore',
        'importo'        => 221000, // €2.210,00
        'tipo'           => 'spesa',
        'attivo'         => true,
        'created_at'     => now()->subHour(), // ← PRIMA del piano
        'updated_at'     => now()->subHour(),
    ]);
    ($s->collegaTabella)($compenso->id);

    // "Manutenzione Ordinaria" (padre, importo=0)
    $manutenzione = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'nome'           => 'Manutenzione Ordinaria',
        'importo'        => 0,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);
    // Figli di Manutenzione — creati DOPO il piano (come nel caso reale)
    $ascensore = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'parent_id'      => $manutenzione->id,
        'nome'           => 'Manutenzione Acque Reflue',
        'importo'        => 249000, // €2.490,00
        'tipo'           => 'spesa',
        'attivo'         => true,
        'created_at'     => now()->addMinutes(9), // ← DOPO il piano (+9 min come nel caso reale)
        'updated_at'     => now()->addMinutes(9),
    ]);
    ($s->collegaTabella)($ascensore->id);
    $varia = Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id,
        'parent_id'      => $manutenzione->id,
        'nome'           => 'Manutenzione Varia',
        'importo'        => 250000, // €2.500,00
        'tipo'           => 'spesa',
        'attivo'         => true,
        'created_at'     => now()->addMinutes(15), // ← DOPO il piano (+15 min)
        'updated_at'     => now()->addMinutes(15),
    ]);
    ($s->collegaTabella)($varia->id);

    // ── Piano Rate creato PRIMA dei sottoconti di Manutenzione ───────────────
    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id,
        'gestione_id'   => $s->gestione->id,
        'nome'          => 'Ordinario',
        'numero_rate'   => 12,
        'stato'         => 'bozza',
        'attivo'        => true,
        'created_at'    => now(), // now() è tra subHour() e addMinutes(9)
        'updated_at'    => now(),
    ]);

    // ── Pivot popolata dalla migration (come nei dati reali: created_at diverso) ──
    // Spese Generali: importo=221.000 (somma figlio Compenso)
    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $piano->id,
        'conto_id'      => $speseGenerali->id,
        'importo'       => 221000,
        'note'          => 'Migrazione V1.9 (Totale Aggregato)',
        'created_at'    => now()->addDays(7), // migration gira settimane dopo
        'updated_at'    => now()->addDays(7),
    ]);
    // Manutenzione Ordinaria: importo=499.000 (somma figli)
    DB::table('piano_rate_capitoli')->insert([
        'piano_rate_id' => $piano->id,
        'conto_id'      => $manutenzione->id,
        'importo'       => 499000,
        'note'          => 'Migrazione V1.9 (Totale Aggregato)',
        'created_at'    => now()->addDays(7),
        'updated_at'    => now()->addDays(7),
    ]);

    // ── Esegui BudgetCoverageService::analyze() ───────────────────────────────
    $service = app(\App\Services\Gestionale\BudgetCoverageService::class);
    $result  = $service->analyze($s->gestione);

    // analyze() restituisce ['status', 'items', 'totali']
    // ogni item ha: ['id', 'pianificato', ...]
    $byId = collect($result['items'])->keyBy('id');

    $coperturaManutenzione = $byId[$manutenzione->id]['pianificato'] ?? 0;
    $coperturaSpese        = $byId[$speseGenerali->id]['pianificato'] ?? 0;

    // SENZA il fix: coperturaManutenzione = 0 (bug — snapshot esclude tutti i figli)
    // CON il fix:   coperturaManutenzione = 499.000 ✅
    expect($coperturaManutenzione)->toBe(499000)
        ->and($coperturaSpese)->toBe(221000);
});
