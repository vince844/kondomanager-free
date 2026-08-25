<?php

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\RipartoTabelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * La scena della segnalazione del 23/08/2026, ricostruita: preventivo da € 1.000,00, una sola
 * tabella al 100% proprietà, saldi di apertura arrivati da un'importazione, piano con rata zero.
 *
 * Copre due cose che si vedono solo insieme:
 *
 *   1. **Un piano con `numero_rate = 1` produce DUE rate.** La rata zero dei saldi di apertura è
 *      una rata a tutti gli effetti, e nasce con la stessa data della prima. Chi legge
 *      «N° rate: 1» sulla scheda del piano e poi conta due colonne sullo scadenziario ha ragione
 *      a stupirsi: è la forma in cui l'amministratore se ne accorge.
 *   2. **Il pregresso non entra nella colonna di una tabella millesimale.** Fino alla beta.73 il
 *      residuo di chi aveva peso in tabella finiva «sulla tabella a peso maggiore», e la colonna
 *      smetteva di valere il deliberato. Ora sta tutto in «addebito diretto».
 *
 * Il caso ristretto al solo punto 2, con i numeri veri della segnalazione, sta in
 * `SaldiNelRipartoTabelleTest`. Qui restano insieme perché è la generazione della rata zero a
 * creare le condizioni del secondo.
 */
function scenarioSegnalazione(): array
{
    $condominio = \App\Models\Condominio::factory()->create(['nome' => 'SEGNALAZIONE']);

    // Esercizio NON solare, come quello dell'utente.
    $esercizio = \App\Models\Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2025',
        'data_inizio' => '2024-11-01', 'data_fine' => '2025-10-31', 'stato' => 'aperto',
    ]);
    $gestione = \App\Models\Gestione::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'nome' => 'Ordinaria', 'tipo' => 'ordinaria',
        'data_inizio' => '2024-11-01', 'data_fine' => '2025-10-31',
    ]);
    $pianoConto = \App\Models\Gestionale\PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'AMMINISTRAZIONE', 'quota' => 'millesimi',
    ]);

    $immobili = [];
    foreach (['A' => 300.0, 'B' => 500.0, 'C' => 200.0] as $k => $mill) {
        $immobili[$k] = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => $k,
            'nome' => "App $k", 'codice_immobile' => "SEG-$k", 'descrizione' => 'Test',
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $immobili[$k]->id,
            'valore' => $mill, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $pa = Anagrafica::factory()->create(['nome' => 'Proprietario A (debito)']);
    $pb = Anagrafica::factory()->create(['nome' => 'Proprietario B (credito)']);
    $pc = Anagrafica::factory()->create(['nome' => 'Proprietario C']);
    $ic = Anagrafica::factory()->create(['nome' => 'Inquilino C']);
    $ex = Anagrafica::factory()->create(['nome' => 'Ex proprietario C']);

    DB::table('anagrafica_immobile')->insert([
        ['anagrafica_id' => $pa->id, 'immobile_id' => $immobili['A']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,  'data_inizio' => now()],
        ['anagrafica_id' => $pb->id, 'immobile_id' => $immobili['B']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,  'data_inizio' => now()],
        ['anagrafica_id' => $pc->id, 'immobile_id' => $immobili['C']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,  'data_inizio' => now()],
        ['anagrafica_id' => $ic->id, 'immobile_id' => $immobili['C']->id, 'tipologia' => 'inquilino',    'quota' => 100, 'attivo' => true,  'data_inizio' => now()],
        // Titolare cessato: resta nella pivot ma NON attivo.
        ['anagrafica_id' => $ex->id, 'immobile_id' => $immobili['C']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => false, 'data_inizio' => now()->subYears(3)],
    ]);

    // Saldi di apertura nominali (come li scrive l'import).
    foreach ([
        [$pa->id, $immobili['A']->id,  120284],   // debito
        [$pb->id, $immobili['B']->id,  -70000],   // credito
        [$ic->id, $immobili['C']->id,  300000],   // debito su INQUILINO
        [$ex->id, $immobili['C']->id,   85095],   // debito su titolare CESSATO
    ] as [$aid, $iid, $importo]) {
        Saldo::create([
            'esercizio_id' => $esercizio->id, 'condominio_id' => $condominio->id,
            'anagrafica_id' => $aid, 'immobile_id' => $iid, 'gestione_id' => $gestione->id,
            'saldo_iniziale' => $importo,
            'origine' => 'importato', 'is_applicato' => false,
        ]);
    }

    // Preventivo: una sola voce da € 1.000,00.
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'nome' => 'am.co comp ord', 'tipo' => 'spesa', 'importo' => 100000,
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabella->id,
        'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        ['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()],
        ['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'inquilino',    'percentuale' => 0,   'created_at' => now(), 'updated_at' => now()],
    ]);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => '2025', 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 1, 'giorno_scadenza' => 5,
        'metodo_distribuzione' => 'rata_zero', 'applica_saldi' => true,
    ]);
    $pianoRate->capitoli()->sync([$conto->id => ['importo' => 100000, 'note' => 'Inclusione automatica orfani']]);

    app(GeneratePianoRateAction::class)->execute($pianoRate);

    return compact('pianoRate', 'tabella', 'immobili', 'pa', 'pb', 'pc', 'ic', 'ex');
}

it('un piano con numero_rate=1 produce DUE rate, e la rata 0 ha la stessa data della rata 1', function () {
    $s = scenarioSegnalazione();
    $rate = $s['pianoRate']->fresh()->rate()->orderBy('numero_rata')->get();

    expect($s['pianoRate']->numero_rate)->toBe(1)
        ->and($rate)->toHaveCount(2)
        ->and($rate[0]->numero_rata)->toBe(0)
        ->and($rate[1]->numero_rata)->toBe(1)
        ->and($rate[0]->data_scadenza->format('Y-m-d'))->toBe('2024-11-05')
        ->and($rate[1]->data_scadenza->format('Y-m-d'))->toBe('2024-11-05');

    // Rata 0 = tutti i saldi; Rata 1 = il preventivo.
    expect((int) $rate[0]->importo_totale)->toBe(120284 - 70000 + 300000 + 85095)
        ->and((int) $rate[1]->importo_totale)->toBe(100000);
});

it('il riparto per tabella tiene il pregresso fuori dalla colonna della tabella', function () {
    $s = scenarioSegnalazione();
    $matrice = (new RipartoTabelleService())->buildMatrice($s['pianoRate']);

    $tabId = $s['tabella']->id;
    $diretto = RipartoTabelleService::COLONNA_DIRETTO;

    // ⚠️ COMPORTAMENTO OSSERVATO, non desiderato.
    //
    // La colonna «AMMINISTRAZIONE» dovrebbe valere il budget deliberato — € 1.000,00 — e invece
    // vale € 1.502,84: dentro ci sono i saldi di apertura dei SOLI proprietari attivi
    // Dopo la correzione della beta.74 il pregresso non entra più nella colonna della tabella:
    // la colonna vale il deliberato (€ 1.000,00) e TUTTO il pregresso — di chi pesa in tabella
    // e di chi non ci pesa — sta nella pseudo-colonna «Addebito diretto».
    //
    // Prima erano 150284 e 385095: i € 502,84 di troppo sui millesimi erano i pregressi dei
    // due proprietari attivi, appoggiati «alla tabella a peso maggiore».
    expect($matrice['tot_per_tabella'][$tabId])->toBe(100000)
        ->and($matrice['tot_per_tabella'][$diretto])->toBe(435379)
        // La cella del proprietario attivo torna a essere la sola quota millesimale: il suo
        // credito di € 700,00 non ci sta più dentro.
        ->and($matrice['righe'][$s['immobili']['B']->id]['soggetti'][$s['pb']->id]['per_tabella'][$tabId]['importo'])->toBe(50000);
});
