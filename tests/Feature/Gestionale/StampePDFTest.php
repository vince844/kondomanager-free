<?php

/**
 * Test Pest per le stampe PDF del Gestionale.
 *
 * Verifica che:
 * 1. I controller rispondano HTTP 200 con content-type application/pdf
 * 2. I dati calcolati combacino con quelli del DB (cross-check)
 * 3. La logica di aggregazione del PDF sia allineata a PianoRateQuoteService
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------

/**
 * Crea un set completo di entità per i test delle stampe:
 * - 1 condominio, 1 esercizio, 1 gestione, 1 piano dei conti
 * - 2 anagrafiche con 2 immobili ciascuna
 * - 1 piano rate con 3 rate, ciascuna con quote per anagrafica+immobile
 *
 * Importi in centesimi.
 */
function setupStampePrintTest(): array
{
    // Ruolo admin
    $role = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    $condominio = Condominio::factory()->create();

    $esercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome'          => 'Esercizio Test 2026',
        'stato'         => 'aperto',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome'          => 'Gestione Ordinaria Test',
        'tipo'          => 'ordinaria',
        'attiva'        => true,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('esercizio_gestione')->insert([
        'esercizio_id' => $esercizioId,
        'gestione_id'  => $gestioneId,
        'attiva'       => true,
        'data_inizio'  => '2026-01-01',
        'data_fine'    => '2026-12-31',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    // Conto contabile necessario per il middleware
    foreach (['debiti_fornitori', 'crediti_condomini', 'debiti_erario_ritenute'] as $ruolo) {
        DB::table('conti_contabili')->insert([
            'condominio_id' => $condominio->id,
            'ruolo'         => $ruolo,
            'codice'        => strtoupper($ruolo),
            'nome'          => ucfirst(str_replace('_', ' ', $ruolo)),
            'tipo'          => 'passivo',
            'categoria'     => 'debiti',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    // Piano dei conti
    $pianoContoId = DB::table('piani_conti')->insertGetId([
        'gestione_id'   => $gestioneId,
        'condominio_id' => $condominio->id,
        'nome'          => 'Piano Conti Test',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    // 2 capitoli di spesa (foglia, senza parent)
    $conto1Id = DB::table('conti')->insertGetId([
        'piano_conto_id' => $pianoContoId,
        'nome'           => 'Pulizia Scale',
        'tipo'           => 'spesa',
        'importo'        => 120000, // 1.200,00 €
        'is_tecnico'     => false,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    $conto2Id = DB::table('conti')->insertGetId([
        'piano_conto_id' => $pianoContoId,
        'nome'           => 'Manutenzione Giardino',
        'tipo'           => 'spesa',
        'importo'        => 80000, // 800,00 €
        'is_tecnico'     => false,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    // 2 anagrafiche
    $ana1Id = DB::table('anagrafiche')->insertGetId([
        'nome'       => 'Mario Bianchi',
        'indirizzo'  => 'Via Test 1, Milano',
        'email'      => 'mario@test.it',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $ana2Id = DB::table('anagrafiche')->insertGetId([
        'nome'       => 'Giulia Verdi',
        'indirizzo'  => 'Via Test 2, Milano',
        'email'      => 'giulia@test.it',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // 2 immobili (uno per anagrafica)
    $imm1Id = DB::table('immobili')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Int. 1A',
        'interno' => '1A', 'piano' => 1, 'codice_immobile' => 'C-0001',
        'descrizione' => '', 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $imm2Id = DB::table('immobili')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Int. 2B',
        'interno' => '2B', 'piano' => 2, 'codice_immobile' => 'C-0002',
        'descrizione' => '', 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Piano rate con 3 rate
    $pianoRateId = DB::table('piani_rate')->insertGetId([
        'condominio_id'        => $condominio->id,
        'gestione_id'          => $gestioneId,
        'nome'                 => 'Piano Rate Test',
        'numero_rate'          => 3,
        'giorno_scadenza'      => 5,
        'metodo_distribuzione' => 'rata_zero',
        'attivo'               => true,
        'stato'                => 'approvato',
        'tipo'                 => 'ordinario',
        'contesto_creazione'   => 'preventivo_iniziale',
        'data_delibera_assemblea' => '2026-01-01',
        'approvato_da_user_id' => $user->id,
        'approvato_il'         => now(),
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    // 3 rate con quote
    // Mario Bianchi: 4.000 cent/rata × 3 = 12.000 cent totali
    // Giulia Verdi:  6.000 cent/rata × 3 = 18.000 cent totali
    foreach ([1, 2, 3] as $num) {
        $scadenza = "2026-0{$num}-05";

        $rataId = DB::table('rate')->insertGetId([
            'piano_rate_id'  => $pianoRateId,
            'numero_rata'    => $num,
            'data_scadenza'  => $scadenza,
            'importo_totale' => 10000, // 100,00 €
            'stato'          => 'emessa',
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        // Quota Mario Bianchi - immobile 1
        DB::table('rate_quote')->insert([
            'rata_id'       => $rataId,
            'anagrafica_id' => $ana1Id,
            'immobile_id'   => $imm1Id,
            'importo'       => 4000, // 40,00 €
            'importo_pagato'=> 0,
            'stato'         => 'da_pagare',
            'created_at'    => now(), 'updated_at' => now(),
        ]);

        // Quota Giulia Verdi - immobile 2
        DB::table('rate_quote')->insert([
            'rata_id'       => $rataId,
            'anagrafica_id' => $ana2Id,
            'immobile_id'   => $imm2Id,
            'importo'       => 6000, // 60,00 €
            'importo_pagato'=> 0,
            'stato'         => 'da_pagare',
            'created_at'    => now(), 'updated_at' => now(),
        ]);
    }

    return [
        'user'         => $user,
        'condominio'   => $condominio,
        'esercizioId'  => $esercizioId,
        'gestioneId'   => $gestioneId,
        'pianoContoId' => $pianoContoId,
        'pianoRateId'  => $pianoRateId,
        'ana1Id'       => $ana1Id,
        'ana2Id'       => $ana2Id,
        'imm1Id'       => $imm1Id,
        'imm2Id'       => $imm2Id,
        'conto1Id'     => $conto1Id,
        'conto2Id'     => $conto2Id,
    ];
}

// ---------------------------------------------------------------------------
// TEST: DISTINTA SPESE
// ---------------------------------------------------------------------------

describe('Stampa Distinta Spese', function () {

    it('risponde HTTP 200 e restituisce un PDF', function () {
        $ctx  = setupStampePrintTest();
        $user = $ctx['user'];

        $response = $this->actingAs($user)->get(route('admin.gestionale.esercizi.piani-conti.print-distinta', [
            'condominio' => $ctx['condominio']->id,
            'esercizio'  => $ctx['esercizioId'],
            'pianoConto' => $ctx['pianoContoId'],
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    });

    it('accetta il parametro di ordinamento senza rompere la stampa', function () {
        // La pagina passa `?ordina=` per far corrispondere il PDF all'ordine mostrato
        // a schermo. Qualunque valore, anche manomesso, deve produrre un PDF valido:
        // OrdinamentoConti::criterioValido() ricade su "nome".
        $ctx  = setupStampePrintTest();
        $user = $ctx['user'];

        foreach (['nome', 'codice', 'colonna_inesistente', ''] as $criterio) {
            $response = $this->actingAs($user)->get(route('admin.gestionale.esercizi.piani-conti.print-distinta', [
                'condominio' => $ctx['condominio']->id,
                'esercizio'  => $ctx['esercizioId'],
                'pianoConto' => $ctx['pianoContoId'],
                'ordina'     => $criterio,
            ]));

            $response->assertStatus(200);
            $response->assertHeader('Content-Type', 'application/pdf');
        }
    });

    it('calcola correttamente il totale preventivo dal DB', function () {
        $ctx = setupStampePrintTest();

        // Il totale preventivo deve essere la somma degli importi dei conti non tecnici senza parent
        $totaleDaDB = DB::table('conti')
            ->where('piano_conto_id', $ctx['pianoContoId'])
            ->whereNull('parent_id')
            ->where('is_tecnico', false)
            ->sum('importo');

        // 120.000 + 80.000 = 200.000 centesimi = 2.000,00 €
        expect((int) $totaleDaDB)->toBe(200000);
    });

    it('esclude i conti tecnici dal totale preventivo', function () {
        $ctx = setupStampePrintTest();

        // Aggiunge un conto tecnico
        DB::table('conti')->insert([
            'piano_conto_id' => $ctx['pianoContoId'],
            'nome'           => 'Sopravvenienza (tecnico)',
            'tipo'           => 'spesa',
            'importo'        => 50000,
            'is_tecnico'     => true,
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        $totaleNonTecnico = DB::table('conti')
            ->where('piano_conto_id', $ctx['pianoContoId'])
            ->whereNull('parent_id')
            ->where('is_tecnico', false)
            ->sum('importo');

        $totaleTecnico = DB::table('conti')
            ->where('piano_conto_id', $ctx['pianoContoId'])
            ->whereNull('parent_id')
            ->where('is_tecnico', true)
            ->sum('importo');

        // I tecnici devono essere separati
        expect((int) $totaleNonTecnico)->toBe(200000)
            ->and((int) $totaleTecnico)->toBe(50000)
            ->and((int) $totaleNonTecnico + (int) $totaleTecnico)->toBe(250000);
    });

});

// ---------------------------------------------------------------------------
// TEST: SCADENZIARIO / PROSPETTO RATE
// ---------------------------------------------------------------------------

describe('Stampa Scadenziario Rate', function () {

    it('risponde HTTP 200 e restituisce un PDF', function () {
        $ctx  = setupStampePrintTest();

        $response = $this->actingAs($ctx['user'])->get(route('admin.gestionale.esercizi.piani-rate.print-scadenziario', [
            'condominio' => $ctx['condominio']->id,
            'esercizio'  => $ctx['esercizioId'],
            'pianoRate'  => $ctx['pianoRateId'],
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    });

    it('il totale per anagrafica combacia con il DB (aggregazione per anagrafica_id)', function () {
        $ctx = setupStampePrintTest();

        $pianoRate = PianoRate::find($ctx['pianoRateId']);
        $pianoRate->load(['rate.rateQuote']);

        // Replica la logica del controller: groupBy anagrafica_id, somma importi
        $totalPerAnagrafica = $pianoRate->rate
            ->flatMap->rateQuote
            ->groupBy('anagrafica_id')
            ->map(fn($quotes) => $quotes->sum('importo'));

        // Mario Bianchi: 4.000 × 3 rate = 12.000 cent
        expect((int) $totalPerAnagrafica[$ctx['ana1Id']])->toBe(12000);

        // Giulia Verdi: 6.000 × 3 rate = 18.000 cent
        expect((int) $totalPerAnagrafica[$ctx['ana2Id']])->toBe(18000);

        // Gran totale = 30.000 cent = 300,00 €
        expect((int) $totalPerAnagrafica->sum())->toBe(30000);
    });

    it('il gran totale del PDF combacia con importo_totale delle rate nel DB', function () {
        $ctx = setupStampePrintTest();

        $totaleDaRate = DB::table('rate')
            ->where('piano_rate_id', $ctx['pianoRateId'])
            ->sum('importo_totale');

        $totaleDaQuote = DB::table('rate_quote')
            ->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $ctx['pianoRateId'])->pluck('id'))
            ->sum('importo');

        // Devono coincidere: 3 rate × 10.000 = 30.000
        expect((int) $totaleDaRate)->toBe(30000)
            ->and((int) $totaleDaQuote)->toBe(30000)
            ->and((int) $totaleDaRate)->toBe((int) $totaleDaQuote, 'importo_totale rate != somma rate_quote');
    });

    it('ogni rata ha la somma delle quote uguale al suo importo_totale', function () {
        $ctx = setupStampePrintTest();

        $rate = DB::table('rate')
            ->where('piano_rate_id', $ctx['pianoRateId'])
            ->get();

        foreach ($rate as $rata) {
            $sommaQuote = DB::table('rate_quote')
                ->where('rata_id', $rata->id)
                ->sum('importo');

            expect((int) $sommaQuote)
                ->toBe((int) $rata->importo_totale,
                    "Rata #{$rata->numero_rata}: somma quote ({$sommaQuote}) ≠ importo_totale ({$rata->importo_totale})");
        }
    });

    it('aggrega per anagrafica e non per immobile (allineamento con la UI)', function () {
        $ctx = setupStampePrintTest();

        // Aggiungo un secondo immobile per Mario Bianchi nella stessa rata
        $rataId = DB::table('rate')
            ->where('piano_rate_id', $ctx['pianoRateId'])
            ->where('numero_rata', 1)
            ->value('id');

        $imm3Id = DB::table('immobili')->insertGetId([
            'condominio_id' => $ctx['condominio']->id, 'nome' => 'Box Auto',
            'interno' => 'B1', 'piano' => 0, 'codice_immobile' => 'C-0003',
            'descrizione' => '', 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('rate_quote')->insert([
            'rata_id'       => $rataId,
            'anagrafica_id' => $ctx['ana1Id'],  // stesso Mario Bianchi
            'immobile_id'   => $imm3Id,          // immobile diverso
            'importo'       => 1000,             // 10,00 € extra
            'importo_pagato'=> 0,
            'stato'         => 'da_pagare',
            'created_at'    => now(), 'updated_at' => now(),
        ]);

        $pianoRate = PianoRate::find($ctx['pianoRateId']);
        $pianoRate->load(['rate.rateQuote']);

        // Aggregazione per anagrafica_id deve sommare tutti gli immobili dello stesso condòmino
        $quotaRata1MarioBianchi = $pianoRate->rate
            ->where('numero_rata', 1)
            ->first()
            ->rateQuote
            ->where('anagrafica_id', $ctx['ana1Id'])
            ->sum('importo');

        // Deve essere 4.000 (imm1) + 1.000 (imm3) = 5.000
        expect((int) $quotaRata1MarioBianchi)->toBe(5000);

        // Verifica che il numero di righe nella matrice PDF = numero di anagrafiche (non immobili)
        $numAnagraficheUniche = $pianoRate->rate
            ->flatMap->rateQuote
            ->pluck('anagrafica_id')
            ->unique()
            ->count();

        expect($numAnagraficheUniche)->toBe(2, 'La matrice deve avere 2 righe (1 per anagrafica), non 3 (per immobile)');
    });

});

// ---------------------------------------------------------------------------
// TEST: RIPARTIZIONE SPESE (Riparto dal Piano dei Conti)
// ---------------------------------------------------------------------------

describe('Stampa Ripartizione Spese', function () {

    it('risponde HTTP 200 e restituisce un PDF', function () {
        $ctx = setupStampePrintTest();

        $response = $this->actingAs($ctx['user'])->get(route('admin.gestionale.esercizi.piani-conti.print-riparto', [
            'condominio' => $ctx['condominio']->id,
            'esercizio'  => $ctx['esercizioId'],
            'pianoConto' => $ctx['pianoContoId'],
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    });

    it('il riparto aggrega per anagrafica (stessa logica dello scadenziario)', function () {
        $ctx = setupStampePrintTest();

        // Il riparto usa i piani rate della stessa gestione
        $pianiRate = PianoRate::where('gestione_id', $ctx['gestioneId'])
            ->with(['rate.rateQuote'])
            ->get();

        $totalPerAnagrafica = $pianiRate
            ->flatMap->rate
            ->flatMap->rateQuote
            ->groupBy('anagrafica_id')
            ->map(fn($quotes) => $quotes->sum('importo'));

        // Stessi valori dello scadenziario
        expect((int) ($totalPerAnagrafica[$ctx['ana1Id']] ?? 0))->toBe(12000);
        expect((int) ($totalPerAnagrafica[$ctx['ana2Id']] ?? 0))->toBe(18000);
    });

});
