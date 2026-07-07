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
 * Ricostruzione COMPLETA del condominio PAR («Residenza al Parco Corpo C»)
 * con i dati reali estratti dal database dell'amministratore (query su
 * quote_tabella, conti, anagrafica_immobile, rate_quote) e incrociati con il
 * PDF di stampa v1.9.1 del 05/07/2026.
 *
 * 44 unità (21 residenziali + 23 garage/posti auto), 8 tabelle, 23 conti.
 * Gli ID reali (immobili 34-84, anagrafiche 39-66) sono usati come chiavi
 * logiche del fixture e mappati sugli ID auto-generati del DB di test.
 *
 * Il totale di ogni (anagrafica × immobile) è verificato al centesimo contro
 * i valori reali di rate_quote (query 4) — questo test è il riferimento di
 * regressione per qualunque modifica al motore di calcolo o alla stampa.
 */

function buildCondominioPar(): array
{
    $condominio = Condominio::factory()->create(['nome' => 'PAR']);
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

    // ── Tabelle (nome => [tipo_quota, decimali, quote per immobile-reale]) ──
    $mcAcqua = [
        34 => 158, 35 => 82, 36 => 79, 38 => 97, 40 => 31, 42 => 56, 44 => 68,
        46 => 137, 48 => 87, 50 => 48, 51 => 65, 52 => 123, 53 => 133, 54 => 41,
        55 => 21, 56 => 135, 57 => 84, 58 => 134, 59 => 110, 60 => 53, 61 => 68,
    ];
    $tunnel = [
        42 => 2.75, 44 => 2.84, 50 => 2.93, 51 => 2.93, 52 => 2.84, 61 => 2.93,
        62 => 2.82, 63 => 2.86, 64 => 2.86, 65 => 2.86, 66 => 2.74, 67 => 3.02,
        68 => 3.15, 69 => 3.62, 72 => 2.75, 73 => 2.84, 74 => 2.84, 75 => 2.93,
        76 => 2.93, 77 => 2.93, 78 => 15.54, 79 => 2.74, 80 => 2.93, 81 => 2.93,
        82 => 2.98, 83 => 3.10, 84 => 2.84,
    ];
    $generale = [
        34 => 34.98, 35 => 34.98, 36 => 34.98, 38 => 44.29, 40 => 44.25,
        42 => 47.00, 44 => 47.65, 46 => 44.29, 48 => 43.73, 50 => 47.21,
        51 => 47.25, 52 => 47.12, 53 => 44.32, 54 => 44.28, 55 => 44.32,
        56 => 44.24, 57 => 44.24, 58 => 44.28, 59 => 44.29, 60 => 44.28,
        61 => 47.17, 62 => 2.82, 63 => 2.86, 64 => 2.86, 65 => 2.86,
        66 => 2.74, 67 => 3.02, 68 => 3.15, 69 => 3.62, 70 => 0.44,
        71 => 0.44, 72 => 2.75, 73 => 2.84, 74 => 2.84, 75 => 2.93,
        76 => 2.93, 77 => 2.93, 78 => 15.54, 79 => 2.74, 80 => 2.93,
        81 => 2.93, 82 => 2.98, 83 => 3.10, 84 => 2.84,
    ];

    // Coerenza dei dati sorgente con i totali stampati nel PDF
    if (abs(array_sum($generale) - 994.24) > 0.001) throw new RuntimeException('GENERALE non somma a 994,24');
    if (abs(array_sum($tunnel) - 91.43) > 0.001) throw new RuntimeException('TUNNEL non somma a 91,43');
    if (array_sum($mcAcqua) !== 1810) throw new RuntimeException('ACQUA CONSUMI non somma a 1810');

    $tabelleDef = [
        'SCALA 07'      => ['millesimi', 2, array_fill_keys([57, 58, 59, 60, 61], 43.73)],
        'SCALA 08'      => ['millesimi', 2, array_fill_keys([50, 51, 52, 53, 54, 55], 43.73)],
        'SCALA 09'      => ['millesimi', 2, array_fill_keys([38, 40, 42, 44, 46, 48], 43.73)],
        'SCALA 10'      => ['millesimi', 2, array_fill_keys([34, 35, 36], 43.73)],
        'ACQUA CONSUMI' => ['mtcubi', 0, $mcAcqua],
        'ACQUA FISSO'   => ['quote', 0, array_fill_keys(array_keys($mcAcqua), 1.0)],
        'GENERALE'      => ['millesimi', 2, $generale],
        'TUNNEL'        => ['millesimi', 2, $tunnel],
    ];

    // ── Proprietari (immobile-reale => anagrafica-reale) + 1 inquilino ──────
    $proprietari = [
        34 => 39, 35 => 40, 36 => 41, 38 => 42, 40 => 43, 42 => 44, 44 => 45,
        46 => 46, 48 => 47, 50 => 48, 51 => 49, 52 => 50, 53 => 51, 54 => 52,
        55 => 53, 56 => 54, 57 => 55, 58 => 56, 59 => 57, 60 => 58, 61 => 59,
        62 => 55, 63 => 54, 64 => 58, 65 => 47, 66 => 43, 67 => 50, 68 => 46,
        69 => 60, 70 => 45, 71 => 47, 72 => 41, 73 => 53, 74 => 61, 75 => 42,
        76 => 62, 77 => 63, 78 => 64, 79 => 65, 80 => 49, 81 => 52, 82 => 53,
        83 => 57, 84 => 47,
    ];
    $inquilini = [35 => 66]; // Telch Mario

    // ── Conti (dalla query 2, ordine per conto_id 130-152) ──────────────────
    $contiDef = [
        [15000, 'SCALA 07', 'inquilino'],
        [15000, 'SCALA 08', 'inquilino'],
        [15000, 'SCALA 09', 'inquilino'],
        [15000, 'SCALA 10', 'inquilino'],
        [220000, 'ACQUA FISSO', 'inquilino'],
        [250000, 'ACQUA CONSUMI', 'inquilino'],
        [35000, 'GENERALE', 'proprietario'],
        [20000, 'GENERALE', 'proprietario'],
        [255000, 'GENERALE', 'proprietario'],
        [25000, 'GENERALE', 'proprietario'],
        [35000, 'GENERALE', 'proprietario'],
        [10000, 'GENERALE', 'proprietario'],
        [10000, 'GENERALE', 'proprietario'],
        [400000, 'GENERALE', 'proprietario'],
        [50000, 'GENERALE', 'inquilino'],
        [15000, 'GENERALE', 'inquilino'],
        [100000, 'GENERALE', 'inquilino'],
        [300000, 'GENERALE', 'inquilino'],
        [0, 'GENERALE', 'proprietario'],
        [600000, 'GENERALE', 'inquilino'],
        [200000, 'GENERALE', 'inquilino'],
        [30000, 'TUNNEL', 'inquilino'],
        [50000, 'TUNNEL', 'proprietario'],
    ];

    // ── Creazione entità (in ordine di ID reale, per riprodurre l'ordine
    //    di inserimento e quindi l'assegnazione dei resti nei tie-break) ─────
    $immobili = [];
    $tuttiRealIds = array_keys($proprietari);
    sort($tuttiRealIds);
    $interno = 0;
    foreach ($tuttiRealIds as $rid) {
        $interno++;
        $immobili[$rid] = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento',
            'interno' => str_pad((string) $interno, 2, '0', STR_PAD_LEFT),
            'nome' => "Unità $rid", 'codice_immobile' => "PAR-$rid", 'descrizione' => 'Reale',
        ]);
    }

    $anagrafiche = [];
    $realAnagIds = array_unique(array_merge(array_values($proprietari), array_values($inquilini)));
    sort($realAnagIds);
    foreach ($realAnagIds as $aidReale) {
        $anagrafiche[$aidReale] = Anagrafica::factory()->create(['nome' => "Anagrafica $aidReale"]);
    }

    foreach ($proprietari as $rid => $aidReale) {
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $anagrafiche[$aidReale]->id, 'immobile_id' => $immobili[$rid]->id,
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ]);
    }
    foreach ($inquilini as $rid => $aidReale) {
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $anagrafiche[$aidReale]->id, 'immobile_id' => $immobili[$rid]->id,
            'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ]);
    }

    $tabelle = [];
    foreach ($tabelleDef as $nome => [$tipoQuota, $decimali, $quote]) {
        $tab = Tabella::create([
            'condominio_id' => $condominio->id, 'nome' => $nome,
            'quota' => $tipoQuota, 'numero_decimali' => $decimali,
        ]);
        $rows = [];
        $ordinati = array_keys($quote);
        sort($ordinati);
        foreach ($ordinati as $rid) {
            $rows[] = [
                'tabella_id' => $tab->id, 'immobile_id' => $immobili[$rid]->id,
                'valore' => $quote[$rid], 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('quote_tabella')->insert($rows);
        $tabelle[$nome] = $tab;
    }

    foreach ($contiDef as $i => [$importo, $tabNome, $soggetto]) {
        $conto = Conto::create([
            'piano_conto_id' => $pianoConto->id, 'nome' => "Conto " . (130 + $i),
            'tipo' => 'spesa', 'importo' => $importo,
        ]);
        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id' => $conto->id, 'tabella_id' => $tabelle[$tabNome]->id,
            'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => $soggetto,
            'percentuale' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => '2026', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    return [$pianoRate, $immobili, $anagrafiche, $tabelle];
}

/**
 * Totali reali per (anagrafica-reale | immobile-reale) da rate_quote
 * (query 4 eseguita dall'amministratore, centesimi).
 */
function totaliRealiPar(): array
{
    return [
        '39|34' => 109602, '40|35' => 27795, '66|35' => 71310, '41|36' => 98690,
        '42|38' => 117917, '43|40' => 108719, '44|42' => 120262, '45|44' => 123338,
        '46|46' => 123441, '47|48' => 115379, '48|50' => 119749, '49|51' => 122176,
        '50|52' => 129844, '51|53' => 122952, '52|54' => 110162, '53|55' => 107482,
        '54|56' => 120560, '55|57' => 116516, '56|58' => 123507, '57|59' => 120211,
        '58|60' => 112319, '59|61' => 122927,
        '55|62' => 8294, '54|63' => 8416, '58|64' => 8416, '47|65' => 8416,
        '43|66' => 8062, '50|67' => 8885, '46|68' => 9268, '60|69' => 10648,
        '45|70' => 910, '47|71' => 910, '41|72' => 8089, '53|73' => 8356,
        '61|74' => 8356, '42|75' => 8621, '62|76' => 8620, '63|77' => 8619,
        '64|78' => 45717, '65|79' => 8062, '49|80' => 8617, '52|81' => 8617,
        '53|82' => 8768, '57|83' => 9119, '47|84' => 8356,
    ];
}

it('il motore riproduce al centesimo tutti i 44 totali reali di rate_quote del PAR', function () {
    [$pianoRate, $immobili, $anagrafiche] = buildCondominioPar();

    $attesi = totaliRealiPar();
    expect(array_sum($attesi))->toBe(2665000); // coerenza interna della query 4

    // Somma reale generata per (anagrafica, immobile)
    $pianoRate->load('rate.rateQuote');
    $generati = [];
    foreach ($pianoRate->rate as $rata) {
        foreach ($rata->rateQuote as $rq) {
            $generati[$rq->anagrafica_id . '|' . $rq->immobile_id] =
                ($generati[$rq->anagrafica_id . '|' . $rq->immobile_id] ?? 0) + (int) $rq->importo;
        }
    }

    $errori = [];
    foreach ($attesi as $chiaveReale => $importoAtteso) {
        [$aidReale, $ridReale] = explode('|', $chiaveReale);
        $chiaveDb = $anagrafiche[(int) $aidReale]->id . '|' . $immobili[(int) $ridReale]->id;
        $generato = $generati[$chiaveDb] ?? null;
        if ($generato !== $importoAtteso) {
            $errori[] = "($chiaveReale) atteso=$importoAtteso generato=" . var_export($generato, true);
        }
    }

    expect($errori)->toBe([]);
    expect(array_sum($generati))->toBe(2665000);
});

it('la stampa riparto ha ogni colonna esatta al budget e ogni riga identica a rate_quote', function () {
    [$pianoRate, $immobili, $anagrafiche, $tabelle] = buildCondominioPar();

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);

    // ── COLONNE: ogni tabella deve battere esattamente il proprio budget ────
    $budgetPerTabella = [
        'SCALA 07' => 15000, 'SCALA 08' => 15000, 'SCALA 09' => 15000, 'SCALA 10' => 15000,
        'ACQUA CONSUMI' => 250000, 'ACQUA FISSO' => 220000,
        'GENERALE' => 2055000, 'TUNNEL' => 80000,
    ];
    foreach ($budgetPerTabella as $nome => $budget) {
        expect($matrice['tot_per_tabella'][$tabelle[$nome]->id])
            ->toBe($budget, "colonna $nome: atteso $budget, trovato " . $matrice['tot_per_tabella'][$tabelle[$nome]->id]);
    }
    expect($matrice['gran_totale'])->toBe(2665000);

    // ── RIGHE: ogni totale soggetto identico al valore legale di rate_quote ─
    foreach (totaliRealiPar() as $chiaveReale => $importoAtteso) {
        [$aidReale, $ridReale] = explode('|', $chiaveReale);
        $riga = $matrice['righe'][$immobili[(int) $ridReale]->id]['soggetti'][$anagrafiche[(int) $aidReale]->id] ?? null;
        expect($riga)->not->toBeNull("riga mancante per $chiaveReale");
        expect($riga['totale'])->toBe($importoAtteso, "riga $chiaveReale: atteso $importoAtteso, trovato {$riga['totale']}");
    }

    // ── ACQUA FISSO: i 4 centesimi di resto restano DENTRO i partecipanti ───
    $afId = $tabelle['ACQUA FISSO']->id;
    $celleAf = [];
    foreach ($matrice['righe'] as $iid => $rigaImm) {
        foreach ($rigaImm['soggetti'] as $aid => $sogg) {
            $imp = $sogg['per_tabella'][$afId]['importo'] ?? 0;
            if ($imp > 0) $celleAf[] = $imp;
        }
    }
    sort($celleAf);
    expect(count($celleAf))->toBe(21);            // solo i 21 partecipanti
    expect(array_sum($celleAf))->toBe(220000);    // colonna esatta
    expect(array_count_values($celleAf))->toBe([10476 => 17, 10477 => 4]); // resto spalmato all'interno

    // ── TUNNEL: nessun centesimo a chi non partecipa (il caso Telch Mario) ──
    $tunId = $tabelle['TUNNEL']->id;
    $telch = $matrice['righe'][$immobili[35]->id]['soggetti'][$anagrafiche[66]->id];
    expect($telch['per_tabella'][$tunId]['importo'])->toBe(0); // in v1.9.1 era €0,01
    expect($telch['totale'])->toBe(71310);                     // ma il suo totale resta esatto
});
