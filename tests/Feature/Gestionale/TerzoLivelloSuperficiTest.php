<?php

use App\Models\{Anagrafica, Condominio, Esercizio, Gestione, Immobile, Tabella, User};
use Illuminate\Support\Facades\DB;
use App\Models\Gestionale\{Conto, PianoConto, PianoRate};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Spatie\Permission\Models\{Permission, Role};

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * beta.51 — Le due superfici che la visibilità del terzo livello ha reso incoerenti.
 *
 * Finché la voce di terzo livello era invisibile, escluderla dai totali di pagina era un difetto
 * che nessuno poteva notare: la riga non c'era e il badge sembrava coerente con l'elenco. Da
 * questa beta la riga c'è, e un preventivo a video accanto a un badge che non lo comprende è una
 * contraddizione sulla stessa schermata — creata da noi, quindi da chiudere qui.
 *
 * Il secondo test presidia una cosa diversa e più seria di un numero sbagliato: una **conferma
 * che mente**. La sincronizzazione automatica delle voci scoperte filtra i soli capitoli di
 * primo livello, quindi su un sotto-conto non include niente — e rispondeva comunque «piano
 * rate ricalcolato con successo». Chi legge chiude la pagina convinto di aver risolto.
 *
 * Cosa questi test NON coprono: la stampa PDF della distinta, che con tre livelli sottostima
 * ancora; e il far funzionare davvero la sincronizzazione sui sotto-conti, che è lavoro sul
 * percorso del denaro e sta fuori dal perimetro di questa beta.
 */
function scenarioSuperfici(): array
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $p = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $r = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $r->givePermissionTo($p);
    $user = User::factory()->create();
    $user->assignRole($r);

    $condominio = Condominio::factory()->create();
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $gestione   = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    legaAEsercizio($esercizio, $gestione->id);
    $pianoConti = PianoConto::factory()->create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
    ]);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'SPESE ORDINARIE', 'tipo' => 'spesa', 'is_tecnico' => false,
    ]);
    $contenitore = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'SPESE AMMINISTRATIVE', 'tipo' => 'spesa', 'is_tecnico' => false,
    ]);
    $nipote = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $contenitore->id, 'is_capitolo' => false,
        'importo' => 240000, 'nome' => 'COMPENSO AMMINISTRATORE', 'tipo' => 'spesa', 'is_tecnico' => false,
    ]);

    // Un immobile con proprietario e la tabella millesimale sulla foglia: senza, la
    // generazione si ferma sugli scoperti e non arriva mai al messaggio da verificare.
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'codice_immobile' => 'C1-001',
        'nome' => 'Int 1', 'descrizione' => 'Unità di prova', 'interno' => '1', 'scala' => 'A',
    ]);
    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
    ]);
    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Millesimi generali',
        'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);
    DB::table('conto_tabella_millesimale')->insert([
        'conto_id' => $nipote->id, 'tabella_id' => $tabella->id,
        'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $immobile->id,
        'valore' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('user', 'condominio', 'esercizio', 'gestione', 'pianoConti', 'capitolo', 'contenitore', 'nipote');
}

test('il badge Preventivo comprende anche le voci di terzo livello', function () {
    $s = scenarioSuperfici();

    $this->actingAs($s['user'])
        ->get(route('admin.gestionale.esercizi.piani-conti.show', [
            $s['condominio'], $s['esercizio'], $s['pianoConti'],
        ]))
        ->assertInertia(fn (AssertableJson $page) =>
            // Prima della beta.51 valeva 0: i cicli si fermavano ai figli diretti, e i
            // € 2.400,00 mostrati nella riga non entravano nel totale sopra di essa.
            $page->where('totalePreventivo', 240000)->etc()
        );
});

test('il ricalcolo non dichiara successo se non ha incluso nessuna delle voci chieste', function () {
    $s = scenarioSuperfici();

    $piano = PianoRate::create([
        'gestione_id'   => $s['gestione']->id,
        'condominio_id' => $s['condominio']->id,
        'nome'          => 'Piano 2026',
        'stato'         => 'bozza',
        'tipo'          => 'ordinario',
        'numero_rate'   => 1,
    ]);

    $this->actingAs($s['user'])
        ->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [
            $s['condominio'], $s['esercizio'], $piano,
        ]), [
            // Un sotto-conto: la sincronizzazione filtra `whereNull('parent_id')` e non lo
            // raggiungerà mai. Prima rispondeva «ricalcolato con successo».
            'orphan_ids' => [$s['nipote']->id],
        ]);

    // Il flash del progetto è una struttura sola: ['message' => ['type' => ..., 'message' => ...]].
    $flash = session('message');

    expect($flash)->not->toBeNull()
        ->and($flash['type'])->toBe('error')
        ->and($flash['message'])->toContain('nessuna delle voci selezionate')
        // Ed è questa la riga che conta: non deve più comparire la parola «successo».
        ->and($flash['message'])->not->toContain('successo');
});
