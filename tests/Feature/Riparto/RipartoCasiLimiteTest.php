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
 * Casi limite per la ricostruzione per-conto della stampa riparto
 * (RipartoTabelleService), oltre il caso reale PAR:
 *
 *  - conto collegato a PIÙ tabelle con coefficienti (60/40)
 *  - comproprietari 50/50 sullo stesso immobile
 *  - ripartizione percentuale proprietario/inquilino sullo stesso conto
 *  - piano con capitoli e importo override (pivot ≠ importo live)
 *  - piano multi-rata (12 rate)
 *
 * Invarianti verificati sempre:
 *  1. ogni riga (totale soggetto) == somma reale di rate_quote
 *  2. la somma delle colonne == gran totale (nessun centesimo perso/creato)
 *  3. per conti mono-tabella: colonna == budget esatto del conto
 */

function baseCondominioCasiLimite(): array
{
    $condominio = Condominio::factory()->create(['nome' => 'CASI-LIMITE']);
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

    return [$condominio, $esercizio, $gestione, $pianoConto];
}

function collegaContoCasiLimite(int $contoId, int $tabellaId, float $coeff, string $soggetto = 'proprietario', float $percent = 100): void
{
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $contoId, 'tabella_id' => $tabellaId,
        'coefficiente' => $coeff, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => $soggetto,
        'percentuale' => $percent, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function verificaInvarianti(PianoRate $pianoRate, array $matrice): void
{
    // Righe == rate_quote (garanzia legale)
    $pianoRate->load('rate.rateQuote');
    $reali = [];
    foreach ($pianoRate->rate as $rata) {
        foreach ($rata->rateQuote as $rq) {
            $reali[$rq->anagrafica_id . '|' . $rq->immobile_id] =
                ($reali[$rq->anagrafica_id . '|' . $rq->immobile_id] ?? 0) + (int) $rq->importo;
        }
    }
    foreach ($matrice['righe'] as $iid => $riga) {
        foreach ($riga['soggetti'] as $aid => $sogg) {
            expect($sogg['totale'])->toBe($reali["$aid|$iid"], "riga $aid|$iid diversa da rate_quote");
            // la riga deve anche essere la somma delle proprie celle
            $sommaCelle = array_sum(array_column($sogg['per_tabella'], 'importo'));
            expect($sommaCelle)->toBe($sogg['totale'], "celle di $aid|$iid non sommano al totale riga");
        }
    }

    // Somma colonne == gran totale (nessun centesimo perso)
    expect(array_sum($matrice['tot_per_tabella']))->toBe($matrice['gran_totale']);
    expect(array_sum($reali))->toBe($matrice['gran_totale']);
}

it('conto multi-tabella 60/40 con comproprietari 50/50: righe legali e nessun centesimo perso', function () {
    [$condominio, , $gestione, $pianoConto] = baseCondominioCasiLimite();

    $tabA = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'TAB A', 'quota' => 'millesimi']);
    $tabB = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'TAB B', 'quota' => 'quote']);

    $millA = [1 => 613.33, 2 => 386.67];
    $quoteB = [1 => 1, 2 => 2];

    $immobili = [];
    foreach ([1, 2] as $i) {
        $immobili[$i] = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $i,
            'nome' => "App $i", 'codice_immobile' => "CL-$i", 'descrizione' => 'Test',
        ]);
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $tabA->id, 'immobile_id' => $immobili[$i]->id, 'valore' => $millA[$i], 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $tabB->id, 'immobile_id' => $immobili[$i]->id, 'valore' => $quoteB[$i], 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // Immobile 1: comproprietari 50/50; immobile 2: proprietario unico
    foreach ([Anagrafica::factory()->create(), Anagrafica::factory()->create()] as $comp) {
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $comp->id, 'immobile_id' => $immobili[1]->id,
            'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => now(),
        ]);
    }
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobili[2]->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
    ]);

    // Importo dispari, non divisibile: 1000,01 € su due tabelle 60/40
    $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Multi', 'tipo' => 'spesa', 'importo' => 100001]);
    collegaContoCasiLimite($conto->id, $tabA->id, 60);
    collegaContoCasiLimite($conto->id, $tabB->id, 40);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Multi 60/40', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);
    verificaInvarianti($pianoRate, $matrice);

    // Le due colonne insieme devono coprire l'intero conto
    expect($matrice['gran_totale'])->toBe(100001);
    // e le colonne devono avvicinarsi allo split ideale 60/40 (±1 cent)
    expect(abs($matrice['tot_per_tabella'][$tabA->id] - 60001))->toBeLessThanOrEqual(1);
    expect(abs($matrice['tot_per_tabella'][$tabB->id] - 40000))->toBeLessThanOrEqual(1);

    // ─── La rete sullo split penny-perfect, aggiunta nella beta.49 ─────────────
    //
    // ⚠️ **Perché serviva.** Fino a qui questo test guardava solo i totali di colonna, con una
    // tolleranza di un centesimo. Ma questo è **l'unico scenario di tutta la suite con un conto
    // collegato a due tabelle**: il golden master `RipartoCondominioParRealeTest`, che si crede
    // la rete del riparto, ha un solo `conto_tabella_millesimale` per conto e quindi prende
    // sempre la scorciatoia mono-tabella. Lo split fra tabelle — l'aritmetica più delicata dei
    // due servizi — non era verificato **da nessuna parte**, e un errore che lascia giuste le
    // righe (il tipo di errore che ci si aspetta, perché il riallineamento le forza) sarebbe
    // passato liscio.
    //
    // I numeri sono l'esito misurato del codice corretto, non un desiderio: colonna A € 600,00 e
    // colonna B € 400,01 (il centesimo dispari finisce su B), l'unità 1 al 61,333% di A e i suoi
    // due comproprietari a metà ciascuno, l'unità 2 con 2 quote su 3 di B.
    $cella = function (int $iid, string $nome, int $tabId) use ($matrice) {
        foreach ($matrice['righe'][$iid]['soggetti'] as $sogg) {
            if ($sogg['nome'] === $nome) return $sogg['per_tabella'][$tabId]['importo'] ?? null;
        }
        return null;
    };

    $nomi = array_column($matrice['righe'][$immobili[1]->id]['soggetti'], 'nome');
    sort($nomi);

    // Unità 1, due comproprietari 50/50: 36.800 su A e 13.334 su B, spaccati a metà.
    foreach ($nomi as $comproprietario) {
        expect($cella($immobili[1]->id, $comproprietario, $tabA->id))->toBe(18400)
            ->and($cella($immobili[1]->id, $comproprietario, $tabB->id))->toBe(6667);
    }

    // Unità 2, proprietario unico.
    $solo = array_values($matrice['righe'][$immobili[2]->id]['soggetti'])[0];
    expect($solo['per_tabella'][$tabA->id]['importo'])->toBe(23200)
        ->and($solo['per_tabella'][$tabB->id]['importo'])->toBe(26667);

    // E le colonne, al centesimo esatto invece che a ±1.
    expect($matrice['tot_per_tabella'][$tabA->id])->toBe(60000)
        ->and($matrice['tot_per_tabella'][$tabB->id])->toBe(40001);
});

it('ripartizione 60% proprietario / 40% inquilino sullo stesso conto: colonna esatta e righe legali', function () {
    [$condominio, , $gestione, $pianoConto] = baseCondominioCasiLimite();

    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'MISTA', 'quota' => 'millesimi']);

    $immobili = [];
    foreach ([1 => 333.33, 2 => 333.33, 3 => 333.34] as $i => $mill) {
        $immobili[$i] = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $i,
            'nome' => "App $i", 'codice_immobile' => "MX-$i", 'descrizione' => 'Test',
        ]);
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $tab->id, 'immobile_id' => $immobili[$i]->id, 'valore' => $mill, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobili[$i]->id,
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ]);
    }
    // Solo l'immobile 2 ha anche un inquilino (per gli altri scatta la cascata)
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobili[2]->id,
        'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
    ]);

    $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Splittato', 'tipo' => 'spesa', 'importo' => 123457]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tab->id,
        'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        ['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 60, 'created_at' => now(), 'updated_at' => now()],
        ['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'inquilino', 'percentuale' => 40, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Split PI', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);
    verificaInvarianti($pianoRate, $matrice);

    // Conto mono-tabella: colonna esatta al budget
    expect($matrice['tot_per_tabella'][$tab->id])->toBe(123457);
});

it('piano con capitolo e importo override (pivot diverso dal live): colonna esatta all\'override', function () {
    [$condominio, , $gestione, $pianoConto] = baseCondominioCasiLimite();

    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'OVERRIDE', 'quota' => 'millesimi']);

    foreach ([1 => 400, 2 => 350, 3 => 250] as $i => $mill) {
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $i,
            'nome' => "App $i", 'codice_immobile' => "OV-$i", 'descrizione' => 'Test',
        ]);
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $tab->id, 'immobile_id' => $immobile->id, 'valore' => $mill, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ]);
    }

    // Importo live 1000,00 ma override nel piano 1234,57
    $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Overridden', 'tipo' => 'spesa', 'importo' => 100000]);
    collegaContoCasiLimite($conto->id, $tab->id, 100);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Con override', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);
    $pianoRate->capitoli()->attach($conto->id, ['importo' => 123457]);
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);
    verificaInvarianti($pianoRate, $matrice);

    expect($matrice['tot_per_tabella'][$tab->id])->toBe(123457);
    expect($matrice['gran_totale'])->toBe(123457);
});

it('piano con 12 rate: la somma delle rate resta identica e le colonne restano esatte', function () {
    [$condominio, , $gestione, $pianoConto] = baseCondominioCasiLimite();

    $tabX = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'X', 'quota' => 'millesimi']);
    $tabY = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Y', 'quota' => 'quote']);

    $millX = [1 => 156.404, 2 => 161.191, 3 => 151.031, 4 => 144.862, 5 => 192.393, 6 => 194.119];
    foreach ($millX as $i => $mill) {
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $i,
            'nome' => "App $i", 'codice_immobile' => "MR-$i", 'descrizione' => 'Test',
        ]);
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $tabX->id, 'immobile_id' => $immobile->id, 'valore' => $mill, 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $tabY->id, 'immobile_id' => $immobile->id, 'valore' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ]);
    }

    // Importi che non si dividono né per 6 né per 12
    $contoX = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'CX', 'tipo' => 'spesa', 'importo' => 1530001]);
    collegaContoCasiLimite($contoX->id, $tabX->id, 100);
    $contoY = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'CY', 'tipo' => 'spesa', 'importo' => 100003]);
    collegaContoCasiLimite($contoY->id, $tabY->id, 100);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Dodici rate', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 12,
    ]);
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    expect($pianoRate->rate()->count())->toBe(12);

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);
    verificaInvarianti($pianoRate, $matrice);

    expect($matrice['tot_per_tabella'][$tabX->id])->toBe(1530001);
    expect($matrice['tot_per_tabella'][$tabY->id])->toBe(100003);
    expect($matrice['gran_totale'])->toBe(1630004);
});
