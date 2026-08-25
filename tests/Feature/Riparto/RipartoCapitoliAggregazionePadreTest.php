<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\RipartoCapitoliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Segnalazione amministratore: la stampa "Riparto per Capitolo e Soggetto"
 * mostrava una colonna per ogni SOTTOCONTO foglia (es. "AM.BK", "AM.CF",
 * "AM.DF"...) invece che una per il CAPITOLO padre ("Amministrative"). Su
 * un condominio con molti sottoconti la tabella HTML risultante è enorme e
 * fa scattare il limite pcre.backtrack_limit di mPDF; su un condominio
 * piccolo il chunking a 6 colonne per pagina (riparto_capitoli.blade.php)
 * mostra solo una parte delle colonne per pagina — la somma dei valori
 * visibili non coincide col totale riga, che invece è sempre corretto
 * perché calcolato su TUTTI i capitoli.
 *
 * Fix: RipartoCapitoliService aggrega pesi/importi sul capitolo RADICE
 * (l'antenato di primo livello), non sulla foglia — una colonna per
 * capitolo reale, qualunque sia il numero di sottoconti che lo compongono.
 */
function contestoAggregazionePadre(): array
{
    $condominio = Condominio::factory()->create();
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    $pianoConto = PianoConto::factory()->create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id]);
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Generale', 'quota' => 'millesimi']);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '1',
        'nome' => 'App 1', 'codice_immobile' => 'AP-1', 'descrizione' => 'Test',
    ]);
    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $immobile->id, 'valore' => 1000.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
    ]);

    return [$pianoConto, $tabella, $gestione];
}

function collegaTabella(int $contoId, int $tabellaId, float $coeff = 100): void
{
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $contoId, 'tabella_id' => $tabellaId,
        'coefficiente' => $coeff, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario',
        'percentuale' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('un capitolo con più sottoconti produce UNA sola colonna (il padre), non una per sottoconto', function () {
    [$pianoConto, $tabella, $gestione] = contestoAggregazionePadre();

    $capitolo = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'Amministrative', 'tipo' => 'spesa',
    ]);
    $bancarie = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => $capitolo->id,
        'importo' => 30000, 'nome' => 'AM.BK Bancarie', 'tipo' => 'spesa',
    ]);
    $compensi = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => $capitolo->id,
        'importo' => 40000, 'nome' => 'AM.CF Compensi', 'tipo' => 'spesa',
    ]);
    collegaTabella($bancarie->id, $tabella->id);
    collegaTabella($compensi->id, $tabella->id);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $pianoConto->condominio_id,
        'nome' => 'Piano test', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);

    $matrice = app(RipartoCapitoliService::class)->buildMatrice($pianoRate);

    // Una sola colonna, quella del capitolo padre — mai le foglie
    expect($matrice['capitoli'])
        ->toHaveKey($capitolo->id)
        ->not->toHaveKey($bancarie->id)
        ->not->toHaveKey($compensi->id);

    // L'importo del capitolo è la SOMMA dei due sottoconti, non uno dei due isolato
    expect($matrice['tot_per_capitolo'][$capitolo->id])->toBe(70000);
    expect($matrice['gran_totale'])->toBe(70000);
});

test('il totale di riga coincide sempre con la somma delle celle per capitolo (nessuna colonna persa)', function () {
    [$pianoConto, $tabella, $gestione] = contestoAggregazionePadre();

    $capitolo = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'Amministrative', 'tipo' => 'spesa',
    ]);
    foreach ([30000, 40000, 20988, 795, 318] as $i => $importo) {
        $figlio = Conto::create([
            'piano_conto_id' => $pianoConto->id, 'parent_id' => $capitolo->id,
            'importo' => $importo, 'nome' => "AM.FIGLIO $i", 'tipo' => 'spesa',
        ]);
        collegaTabella($figlio->id, $tabella->id);
    }

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $pianoConto->condominio_id,
        'nome' => 'Piano test', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);

    $matrice = app(RipartoCapitoliService::class)->buildMatrice($pianoRate);
    $riga = collect($matrice['righe'])->first();
    $soggetto = collect($riga['soggetti'])->first();

    $sommaCelle = array_sum(array_column($soggetto['per_capitolo'], 'importo'));

    expect($sommaCelle)->toBe($soggetto['totale']);
    expect($soggetto['totale'])->toBe(30000 + 40000 + 20988 + 795 + 318);
});

test('sottoconti dello stesso capitolo su tabelle diverse: importo esatto, quota segnata come mista', function () {
    [$pianoConto, $tabellaA, $gestione] = contestoAggregazionePadre();
    $tabellaB = Tabella::create(['condominio_id' => $pianoConto->condominio_id, 'nome' => 'Ascensore', 'quota' => 'millesimi']);

    $capitolo = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'Miste', 'tipo' => 'spesa',
    ]);
    $suTabellaA = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => $capitolo->id,
        'importo' => 10000, 'nome' => 'MI.A Su tabella A', 'tipo' => 'spesa',
    ]);
    $suTabellaB = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => $capitolo->id,
        'importo' => 5000, 'nome' => 'MI.B Su tabella B', 'tipo' => 'spesa',
    ]);
    collegaTabella($suTabellaA->id, $tabellaA->id);
    // Nessuna quota_tabella per tabellaB sull'immobile del contesto: irrilevante,
    // ci basta che la SECONDA tabella collegata differisca dalla prima per
    // far scattare 'quota_mista' — l'importo deve restare comunque esatto.
    $immobile = \App\Models\Immobile::create([
        'condominio_id' => $pianoConto->condominio_id, 'tipo' => 'appartamento', 'interno' => '9',
        'nome' => 'App 9', 'codice_immobile' => 'AP-9', 'descrizione' => 'Test',
    ]);
    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabellaB->id, 'immobile_id' => $immobile->id, 'valore' => 500.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $anagraficaB = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagraficaB->id, 'immobile_id' => $immobile->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
    ]);
    collegaTabella($suTabellaB->id, $tabellaB->id);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $pianoConto->condominio_id,
        'nome' => 'Piano test', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);

    $matrice = app(RipartoCapitoliService::class)->buildMatrice($pianoRate);

    expect($matrice['tot_per_capitolo'][$capitolo->id])->toBe(15000);
    expect($matrice['capitoli'][$capitolo->id]['quota_mista'])->toBeTrue();
    expect($matrice['capitoli'][$capitolo->id]['quota_label'])->toBe('—');
});

test('la stampa PDF risponde 200 con un capitolo a molti sottoconti (nessun crash mPDF)', function () {
    [$pianoConto, $tabella, $gestione] = contestoAggregazionePadre();
    $condominio = Condominio::find($pianoConto->condominio_id);
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    legaAEsercizio($esercizio, $gestione->id);

    $role = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    // Un capitolo con parecchi sottoconti foglia: prima del fix, ognuno
    // diventava una colonna a sé — qui verifichiamo solo che il rendering
    // regga (200 + PDF valido), non le dimensioni esatte dell'HTML generato.
    $capitolo = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'Amministrative', 'tipo' => 'spesa',
    ]);
    for ($i = 0; $i < 8; $i++) {
        $figlio = Conto::create([
            'piano_conto_id' => $pianoConto->id, 'parent_id' => $capitolo->id,
            'importo' => 1000 * ($i + 1), 'nome' => "AM.F$i Sottoconto $i", 'tipo' => 'spesa',
        ]);
        collegaTabella($figlio->id, $tabella->id);
    }

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Piano test stampa', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);

    $response = $this->actingAs($user)->get(route('admin.gestionale.esercizi.piani-rate.print-riparto-capitoli', [
        'condominio' => $condominio->id,
        'esercizio'  => $esercizio->id,
        'pianoRate'  => $pianoRate->id,
    ]));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('un condominio grande (molti capitoli e unità) non fa crashare mPDF anche con l\'aggregazione', function () {
    // Verificato con un test di stress dedicato: l'aggregazione per padre da
    // sola NON basta a scala reale — 25 capitoli × 4 sottoconti × 40 unità
    // produce comunque ~1,55 MB di HTML, sopra il default PHP di
    // pcre.backtrack_limit (1 MB), e mPDF rifiuta la generazione con un 500.
    // PdfService alza quel limite prima di ogni WriteHTML(): qui verifichiamo
    // che la stampa regga a questa scala end-to-end (rotta HTTP → PDF vero).
    [$pianoConto, $tabella, $gestione] = contestoAggregazionePadre();
    $condominio = Condominio::find($pianoConto->condominio_id);
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    legaAEsercizio($esercizio, $gestione->id);

    $role = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    for ($i = 2; $i <= 40; $i++) {
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $i,
            'nome' => "Int. $i", 'codice_immobile' => "STR-$i", 'descrizione' => '',
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $immobile->id, 'valore' => 25.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $prop = Anagrafica::factory()->create();
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $prop->id, 'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ]);
    }

    for ($c = 1; $c <= 25; $c++) {
        $capitolo = Conto::create([
            'piano_conto_id' => $pianoConto->id, 'parent_id' => null, 'is_capitolo' => true,
            'importo' => 0, 'nome' => "Capitolo $c", 'tipo' => 'spesa',
        ]);
        for ($s = 1; $s <= 4; $s++) {
            $figlio = Conto::create([
                'piano_conto_id' => $pianoConto->id, 'parent_id' => $capitolo->id,
                'importo' => 1000 * $s, 'nome' => "C$c.S$s", 'tipo' => 'spesa',
            ]);
            collegaTabella($figlio->id, $tabella->id);
        }
    }

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Preventivo grande', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);

    $response = $this->actingAs($user)->get(route('admin.gestionale.esercizi.piani-rate.print-riparto-capitoli', [
        'condominio' => $condominio->id, 'esercizio' => $esercizio->id, 'pianoRate' => $pianoRate->id,
    ]));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
})->skip(fn () => (int) ini_get('pcre.backtrack_limit') === 0, 'pcre.backtrack_limit=0 (illimitato) nell\'ambiente corrente: il caso che questo test riproduce non può verificarsi qui.');

test('un capitolo con tabella propria (senza figli) resta invariato: retrocompatibilità', function () {
    [$pianoConto, $tabella, $gestione] = contestoAggregazionePadre();

    $voce = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'parent_id' => null, 'is_capitolo' => false,
        'importo' => 50000, 'nome' => 'Manutenzione Ascensore', 'tipo' => 'spesa',
    ]);
    collegaTabella($voce->id, $tabella->id);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $pianoConto->condominio_id,
        'nome' => 'Piano test', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);

    $matrice = app(RipartoCapitoliService::class)->buildMatrice($pianoRate);

    expect($matrice['capitoli'])->toHaveKey($voce->id);
    expect($matrice['tot_per_capitolo'][$voce->id])->toBe(50000);
});
