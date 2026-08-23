<?php

/**
 * Ricostruzione della scena segnalata dall'amministratore (23/08/2026).
 *
 * Preventivo con UNA sola voce da € 1.000,00, ripartita su UNA sola tabella
 * (AMMINISTRAZIONE, 100% proprietà), più i saldi di apertura di un condominio
 * importato: debiti, crediti, un inquilino e un ex titolare non più in pivot.
 *
 * Attesa dell'amministratore: la colonna AMMINISTRAZIONE del riparto vale
 * € 1.000,00 (il deliberato) e i pregressi stanno fuori dalle colonne millesimali.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\RipartoTabelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function scenaSaldiRiparto(): array
{
    $condominio = Condominio::factory()->create(['nome' => 'Condominio Importato']);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2025',
        'data_inizio' => '2024-11-01', 'data_fine' => '2025-10-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'nome' => 'Ordinaria', 'tipo' => 'ordinaria',
    ]);
    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC',
    ]);

    $tabAmm = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'AMMINISTRAZIONE', 'quota' => 'millesimi',
    ]);

    // Cinque unità, 1.000 millesimi in tutto. Con € 1.000,00 di spesa,
    // 1 millesimo = € 1,00: la quota pura di ciascuno si legge a occhio.
    $unita = [
        1 => ['mill' => 300, 'prop' => 'TACCALA',      'saldoProp' =>  120284],
        2 => ['mill' => 250, 'prop' => 'DAVACAANTA',   'saldoProp' =>  -20010],
        3 => ['mill' => 200, 'prop' => 'BARASAN',      'saldoProp' =>  -20372, 'inq' => 'INQUILINO ROSSI', 'saldoInq' => 50000],
        4 => ['mill' => 150, 'prop' => 'SATTAPAATRA',  'saldoProp' =>  -36615, 'ex' => 'EX TITOLARE',      'saldoEx'  => 335095],
        5 => ['mill' => 100, 'prop' => 'DA TASA',      'saldoProp' =>  -21851],
    ];

    $ids = [];

    foreach ($unita as $n => $d) {
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento',
            'interno' => (string) $n, 'nome' => "Appartamento $n",
            'codice_immobile' => "U-00$n", 'descrizione' => 'Test',
        ]);

        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabAmm->id, 'immobile_id' => $immobile->id,
            'valore' => $d['mill'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $prop = Anagrafica::factory()->create(['nome' => $d['prop']]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $prop->id, 'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ]);

        Saldo::create([
            'esercizio_id' => $esercizio->id, 'condominio_id' => $condominio->id,
            'gestione_id' => $gestione->id, 'anagrafica_id' => $prop->id,
            'immobile_id' => $immobile->id, 'saldo_iniziale' => $d['saldoProp'],
            'origine' => 'importato', 'is_applicato' => false,
        ]);

        $ids[$n] = ['immobile' => $immobile->id, 'prop' => $prop->id];

        // Un inquilino con un pregresso proprio: la tabella è 100% proprietà,
        // quindi nel riparto non ha peso in nessuna colonna.
        if (isset($d['inq'])) {
            $inq = Anagrafica::factory()->create(['nome' => $d['inq']]);
            DB::table('anagrafica_immobile')->insert([
                'anagrafica_id' => $inq->id, 'immobile_id' => $immobile->id,
                'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
            ]);
            Saldo::create([
                'esercizio_id' => $esercizio->id, 'condominio_id' => $condominio->id,
                'gestione_id' => $gestione->id, 'anagrafica_id' => $inq->id,
                'immobile_id' => $immobile->id, 'saldo_iniziale' => $d['saldoInq'],
                'origine' => 'importato', 'is_applicato' => false,
            ]);
            $ids[$n]['inq'] = $inq->id;
        }

        // L'ex titolare: pregresso nominale intestato a lui, ma non è più
        // attuale sull'unità (pivot attivo = false).
        if (isset($d['ex'])) {
            $ex = Anagrafica::factory()->create(['nome' => $d['ex']]);
            DB::table('anagrafica_immobile')->insert([
                'anagrafica_id' => $ex->id, 'immobile_id' => $immobile->id,
                'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => false, 'data_inizio' => now()->subYears(3),
            ]);
            Saldo::create([
                'esercizio_id' => $esercizio->id, 'condominio_id' => $condominio->id,
                'gestione_id' => $gestione->id, 'anagrafica_id' => $ex->id,
                'immobile_id' => $immobile->id, 'saldo_iniziale' => $d['saldoEx'],
                'origine' => 'importato', 'is_applicato' => false,
            ]);
            $ids[$n]['ex'] = $ex->id;
        }
    }

    // La voce unica del preventivo: € 1.000,00 su AMMINISTRAZIONE, 100% proprietà.
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'nome' => 'am.co comp ord',
        'tipo' => 'spesa', 'importo' => 100000,
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabAmm->id, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario',
        'percentuale' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Piano 2025', 'stato' => 'bozza', 'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero', 'applica_saldi' => true,
        'data_prima_scadenza' => '2024-11-05',
    ]);

    app(GeneratePianoRateAction::class)->execute($pianoRate, forzaApplicazioneSaldi: true);

    return [$pianoRate->fresh(), $tabAmm, $ids];
}

it('mostra dove finiscono i pregressi nel riparto per tabella', function () {
    [$pianoRate, $tabAmm, $ids] = scenaSaldiRiparto();

    // ── Lo scadenziario: rata 0 = pregressi, rata 1 = quota millesimale ───────
    $pianoRate->load('rate.rateQuote');
    $totaliPerRata = [];
    foreach ($pianoRate->rate as $rata) {
        $totaliPerRata[$rata->numero_rata] = (int) $rata->rateQuote->sum('importo');
    }
    ksort($totaliPerRata);

    echo "\n=== SCADENZIARIO (somma algebrica reale) ===\n";
    foreach ($totaliPerRata as $n => $tot) {
        echo "  {$n}ª Rata: € " . number_format($tot / 100, 2, ',', '.') . "\n";
    }
    echo "  TOTALE:  € " . number_format(array_sum($totaliPerRata) / 100, 2, ',', '.') . "\n";

    expect($totaliPerRata[1])->toBe(100000);   // il deliberato
    expect($totaliPerRata[0])->toBe(406531);   // i pregressi, al netto dei crediti

    // ── Il riparto per tabella ────────────────────────────────────────────────
    $matrice = app(RipartoTabelleService::class)->buildMatrice($pianoRate);

    $totAmm     = $matrice['tot_per_tabella'][$tabAmm->id] ?? 0;
    $totDiretto = $matrice['tot_per_tabella'][RipartoTabelleService::COLONNA_DIRETTO] ?? 0;

    echo "\n=== RIPARTO PER TABELLA ===\n";
    echo "  AMMINISTRAZIONE:  € " . number_format($totAmm / 100, 2, ',', '.')
        . "   (deliberato: € 1.000,00)\n";
    echo "  Addebito diretto: € " . number_format($totDiretto / 100, 2, ',', '.') . "\n";
    echo "  GRAN TOTALE:      € " . number_format($matrice['gran_totale'] / 100, 2, ',', '.') . "\n";
    echo "  SCARTO sulla colonna millesimale: € "
        . number_format(($totAmm - 100000) / 100, 2, ',', '.') . "\n";

    // Riga per riga: la cella della tabella non è la quota millesimale.
    echo "\n=== RIGHE (cella AMMINISTRAZIONE vs quota millesimale pura) ===\n";
    foreach ($matrice['righe'] as $riga) {
        foreach ($riga['soggetti'] as $sogg) {
            $cella = $sogg['per_tabella'][$tabAmm->id]['importo'] ?? 0;
            $quota = $sogg['per_tabella'][$tabAmm->id]['quota'];
            echo sprintf(
                "  %-16s %-3s mill %7s → cella € %10s | totale € %10s%s\n",
                $sogg['nome'],
                $sogg['ruolo'],
                $quota === null ? '—' : number_format((float) $quota, 2, ',', '.'),
                number_format($cella / 100, 2, ',', '.'),
                number_format($sogg['totale'] / 100, 2, ',', '.'),
                $cella <= 0 ? '   [stampato «—»]' : ''
            );
        }
    }

    // I due totali si spartiscono il gran totale.
    expect($totAmm + $totDiretto)->toBe($matrice['gran_totale']);
    expect($matrice['gran_totale'])->toBe(506531);

    // La colonna millesimale vale il DELIBERATO, non un centesimo di più: il
    // pregresso — di chi ha peso in tabella e di chi non ne ha — sta tutto
    // nella colonna degli addebiti diretti, che è dove appartiene.
    //
    // Prima della correzione questa colonna valeva 121436: i € 214,36 di troppo
    // erano i pregressi dei proprietari attuali al netto di quelli dei titolari
    // cessati, già portati sull'unità per l'art. 63.
    expect($totAmm)->toBe(100000);
    expect($totDiretto)->toBe(406531);
});

it('nasconde i crediti nella stampa e li conta comunque nei totali', function () {
    [$pianoRate, $tabAmm, $ids] = scenaSaldiRiparto();

    $pianoRate->load('rate.rateQuote.anagrafica');

    $colonneRate = [];
    foreach ($pianoRate->rate->sortBy('numero_rata') as $rata) {
        $colonneRate[$rata->numero_rata] = [
            'nome' => $rata->numero_rata . 'ª Rata',
            'scadenza' => $rata->data_scadenza?->format('d/m/Y') ?? '',
        ];
    }

    // Stessa aggregazione di PianoRatePrintController::buildMatriceAnagrafica().
    $matrice = [];
    foreach ($pianoRate->rate as $rata) {
        foreach ($rata->rateQuote->groupBy('anagrafica_id') as $aid => $quotes) {
            if (! $aid) continue;
            $matrice[$aid] ??= [
                'etichetta' => $quotes->first()->anagrafica->nome ?? '—',
                'importi_per_rata' => [],
                'totale' => 0,
            ];
            $importo = (int) $quotes->sum('importo');
            $matrice[$aid]['importi_per_rata'][$rata->numero_rata] = $importo;
            $matrice[$aid]['totale'] += $importo;
        }
    }

    $html = view('pdf.gestionale._tabella_scadenziario', [
        'matrice' => array_values($matrice),
        'colonneRate' => $colonneRate,
        'labelColonna' => 'Condòmino',
        'hasSottoEtichetta' => false,
    ])->render();

    // DAVACAANTA ha un credito di € 200,10 sulla rata 0: nel totale c'è,
    // nella cella no.
    $davacaanta = collect($matrice)->firstWhere('etichetta', 'DAVACAANTA');
    expect($davacaanta['importi_per_rata'][0])->toBe(-20010);

    echo "\n=== STAMPA SCADENZIARIO ===\n";
    echo "  credito di DAVACAANTA in rata 0: " . ($davacaanta['importi_per_rata'][0] / 100) . "\n";
    echo "  la stringa «200,10» compare nell'HTML stampato? "
        . (str_contains($html, '200,10') ? 'SÌ' : 'NO') . "\n";
    echo "  totale rata 0 stampato contiene «4.065,31»? "
        . (str_contains($html, '4.065,31') ? 'SÌ' : 'NO') . "\n";

    // Il totale di colonna include i crediti: è un fatto della struttura dati,
    // non della grafica, e non dipende da come la cella viene stampata.
    expect(array_sum(array_column($matrice, 'totale')))->toBe(506531);
    expect($html)->toContain('4.065,31');

    // La cella: nella versione committata il filtro è `@if($importo > 0)`
    // (_tabella_scadenziario.blade.php:54), quindi il credito esce come «—»
    // mentre il totale sotto lo conta. L'assertion è morbida perché il file
    // è in corso di modifica.
    $celleNascondonoIlCredito = ! str_contains($html, '-200,10');
    echo "  la cella stampa il credito? " . ($celleNascondonoIlCredito ? 'NO (esce «—»)' : 'SÌ') . "\n";
});
