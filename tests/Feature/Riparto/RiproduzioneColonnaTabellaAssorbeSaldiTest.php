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
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\RipartoTabelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Scenario dell'utente, ridotto all'osso:
 *   - UNA voce di spesa da 1.000,00 €
 *   - UNA tabella millesimale, coefficiente 100, ripartizione proprietario 100%
 *   - saldi di apertura: un debito, un credito, e un saldo su un inquilino
 *
 * La colonna della tabella DOVREBBE valere 1.000,00 €.
 */
it('la colonna della tabella assorbe i saldi di apertura invece del solo preventivo', function () {
    // ⚠️ RIPRODUZIONE DI UN DIFETTO APERTO, non un test di regressione.
    // Documenta lo scenario segnalato il 23/08/2026: colonna a 1.200,00 € invece di 1.000,00 €.
    // Va tolto lo skip quando si corregge RipartoTabelleService:268-313.
    $condominio = Condominio::factory()->create();

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome'          => '2026',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'stato'         => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'esercizio_id'  => $esercizio->id,
        'nome'          => 'Gestione ordinaria',
        'tipo'          => 'ordinaria',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
    ]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
        'nome'          => 'PC 2026',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'AMMINISTRAZIONE',
        'tipo'          => 'standard',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    // ── tre unità, millesimi 500 / 300 / 200 ────────────────────────────────
    $immobili = [];
    foreach ([['A', 500.0], ['B', 300.0], ['C', 200.0]] as $i => [$sigla, $mill]) {
        $imm = Immobile::create([
            'condominio_id'   => $condominio->id,
            'tipo'            => 'appartamento',
            'codice_immobile' => 'U-'.$sigla,
            'nome'            => 'Appartamento '.$sigla,
            'interno'         => (string) ($i + 1),
            'descrizione'     => 'Unità '.$sigla,
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id'  => $tabella->id,
            'immobile_id' => $imm->id,
            'valore'      => $mill,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        $immobili[$sigla] = $imm;
    }

    // ── proprietari + un inquilino sull'unità C ─────────────────────────────
    $propA = Anagrafica::factory()->create(['nome' => 'PROP A']);
    $propB = Anagrafica::factory()->create(['nome' => 'PROP B']);
    $propC = Anagrafica::factory()->create(['nome' => 'PROP C']);
    $inqC  = Anagrafica::factory()->create(['nome' => 'INQ C']);

    $pivot = [
        [$propA->id, $immobili['A']->id, 'proprietario'],
        [$propB->id, $immobili['B']->id, 'proprietario'],
        [$propC->id, $immobili['C']->id, 'proprietario'],
        [$inqC->id,  $immobili['C']->id, 'inquilino'],
    ];
    foreach ($pivot as [$aid, $iid, $ruolo]) {
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $aid,
            'immobile_id'   => $iid,
            'tipologia'     => $ruolo,
            'quota'         => 100,
            'attivo'        => true,
            'data_inizio'   => now(),
        ]);
    }

    // ── UNA sola voce di spesa: 1.000,00 € ──────────────────────────────────
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome'           => 'am.co comp ord',
        'tipo'           => 'spesa',
        'importo'        => 100000,
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id'     => $conto->id,
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

    // ── saldi di apertura ───────────────────────────────────────────────────
    // positivo = debito, negativo = credito
    $saldi = [
        [$propA->id, $immobili['A']->id,  100000], // debito 1.000,00
        [$propB->id, $immobili['B']->id,  -80000], // credito   800,00
        [$inqC->id,  $immobili['C']->id,   30000], // debito    300,00 su chi non ha peso in tabella
    ];
    foreach ($saldi as [$aid, $iid, $importo]) {
        Saldo::create([
            'esercizio_id'   => $esercizio->id,
            'condominio_id'  => $condominio->id,
            'gestione_id'    => $gestione->id,
            'anagrafica_id'  => $aid,
            'immobile_id'    => $iid,
            'saldo_iniziale' => $importo,
            'origine'        => 'importato',
            'is_applicato'   => false,
        ]);
    }

    $pianoRate = PianoRate::create([
        'gestione_id'          => $gestione->id,
        'condominio_id'        => $condominio->id,
        'nome'                 => 'Piano 2026',
        'stato'                => 'bozza',
        'tipo'                 => 'ordinario',
        'numero_rate'          => 1,
        'metodo_distribuzione' => 'rata_zero',
    ]);

    app(GeneratePianoRateAction::class)->execute($pianoRate, forzaApplicazioneSaldi: true);

    $matrice = app(RipartoTabelleService::class)->buildMatrice($pianoRate);

    $colonnaTabella = $matrice['tot_per_tabella'][$tabella->id] ?? 0;
    $colonnaDiretto = $matrice['tot_per_tabella'][RipartoTabelleService::COLONNA_DIRETTO] ?? 0;

    dump([
        'colonna AMMINISTRAZIONE (cent)' => $colonnaTabella,
        'colonna Addebito diretto (cent)' => $colonnaDiretto,
        'gran totale (cent)' => $matrice['gran_totale'],
        'celle per soggetto' => collect($matrice['righe'])->mapWithKeys(fn ($r) => [
            $r['nome_immobile'] => collect($r['soggetti'])->mapWithKeys(fn ($s) => [
                $s['nome'].' ('.$s['ruolo'].')' => [
                    'cella tabella' => $s['per_tabella'][$tabella->id]['importo'] ?? null,
                    'cella diretto' => $s['per_tabella'][RipartoTabelleService::COLONNA_DIRETTO]['importo'] ?? null,
                    'totale riga'   => $s['totale'],
                ],
            ])->all(),
        ])->all(),
    ]);

    // ── Cosa finisce davvero sulla carta ────────────────────────────────────
    $html = \Illuminate\Support\Facades\View::make('pdf.gestionale.riparto_tabelle', [
        'condominio' => $condominio,
        'esercizio'  => $esercizio,
        'pianoRate'  => $pianoRate,
        'matrice'    => $matrice,
        'nTabelle'   => count($matrice['tabelle']),
    ])->render();

    // Isola la riga di PROP B e conta le celle
    preg_match('/PROP B.*?<\/tr>/s', $html, $m);
    $rigaB = preg_replace('/\s+/', ' ', strip_tags($m[0] ?? ''));

    dump([
        'riga stampata di PROP B'          => $rigaB,
        'occorrenze di «-500,00» nel PDF'  => substr_count($html, '-500,00'),
        'stampa: totale colonna 1.200,00'  => str_contains($html, '1.200,00'),
    ]);

    // Il preventivo è 1.000,00 €. Questa è l'asserzione che DEVE valere.
    expect($colonnaTabella)->toBe(100000);
});
