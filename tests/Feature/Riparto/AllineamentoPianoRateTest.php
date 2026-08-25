<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * beta.48 — «Questo piano è allineato al preventivo?» ha **una** risposta sola.
 *
 * Fino all'11/08/2026 ne aveva due, e potevano contraddirsi:
 *
 * - il **cruscotto** in dashboard sommava `regole_calcolo.importi.quota_pura_gestione` e
 *   confrontava con i capitoli, tolleranza **zero**;
 * - la **pagina del piano** ricavava lo stesso numero in TypeScript sottraendo i saldi
 *   pregressi dal totale generato, tolleranza **€ 2,00**.
 *
 * Un piano scostato di € 1,00 era verde sulla pagina e «URGENTE» in dashboard. È la forma di
 * difetto della beta.44 — la stessa domanda con due risposte in due posti — e questa volta le
 * due risposte vivevano perfino in due linguaggi.
 *
 * ## Perché la sottrazione era anche fragile
 *
 * Un **saldo solidale** ha `anagrafica_id` nullo — il debito segue l'unità, non la persona
 * (art. 63 disp. att. c.c.) — quindi non compare in nessuna aggregazione per persona. La
 * sottrazione lo mancava e il badge restava acceso per sempre: verificato a video il 10/08/2026
 * sul piano 207 di Demo KM, € 1.200,00 di solidale.
 *
 * Leggere la componente pura non ha quel problema **per costruzione**: il pregresso non entra
 * mai nella somma, quindi non c'è niente da sottrarre. È il motivo per cui la correzione del
 * 10/08 (`totaleSaldiPregressiCents()`) è stata cancellata invece che tenuta.
 *
 * ## Cosa questo file NON copre
 *
 * - I piani **straordinari**, il cui atteso viene dalle fatture collegate e non dai capitoli:
 *   il ramo c'è in `totaleAttesoCents()`, nessun caso qui lo esercita.
 * - Il **ripiego** per le quote senza `quota_pura_gestione` nello snapshot: sui dati reali non
 *   si attiva mai (0 su 369) ed esiste per i piani generati prima che la chiave esistesse.
 */
function scenarioAllineamento(int $importoCapitolo = 240000): object
{
    $condominio = Condominio::factory()->create();

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'nome' => 'Ordinaria',
        'tipo' => 'ordinaria',
        'descrizione' => 'Ordinaria',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'nome' => 'PC 2026',
    ]);

    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome' => 'Spese generali',
        'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria',
        'importo' => $importoCapitolo,
    ]);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome' => 'Piano 2026',
        'stato' => 'bozza',
        'tipo' => 'ordinario',
        'numero_rate' => 1,
    ]);
    $pianoRate->capitoli()->attach($conto->id, ['importo' => $importoCapitolo]);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'codice_immobile' => 'C1-001',
        'nome' => 'Int 1',
        'descrizione' => 'Unità di prova',
        'interno' => '1',
    ]);

    $anagrafica = Anagrafica::factory()->create();

    return (object) compact('condominio', 'esercizio', 'gestione', 'pianoRate', 'immobile', 'anagrafica');
}

/** Aggiunge una rata con una quota, dichiarando quanto di quell'importo è spesa pura. */
function conQuota(object $s, int $importo, int $quotaPura, int $saldoUsato = 0, int $numeroRata = 1): void
{
    $rataId = DB::table('rate')->insertGetId([
        'piano_rate_id'  => $s->pianoRate->id,
        'numero_rata'    => $numeroRata,
        'importo_totale' => $importo,
        'data_scadenza'  => '2026-01-05',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    DB::table('rate_quote')->insert([
        'rata_id'        => $rataId,
        'anagrafica_id'  => $s->anagrafica->id,
        'immobile_id'    => $s->immobile->id,
        'importo'        => $importo,
        'importo_pagato' => 0,
        'regole_calcolo' => json_encode([
            'importi' => ['quota_pura_gestione' => $quotaPura, 'saldo_usato' => $saldoUsato],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function verdetto(object $s): bool
{
    return app(PianoRateQuoteService::class)->eDisallineato($s->pianoRate->fresh());
}

test('un piano la cui spesa pura copre i capitoli è allineato', function () {
    $s = scenarioAllineamento(240000);
    conQuota($s, importo: 240000, quotaPura: 240000);

    expect(verdetto($s))->toBeFalse();
});

test('un piano a cui manca una parte del capitolo è disallineato', function () {
    $s = scenarioAllineamento(240000);
    conQuota($s, importo: 200000, quotaPura: 200000);

    expect(verdetto($s))->toBeTrue();
});

test('il pregresso non conta come spesa, e non fa sembrare il piano allineato', function () {
    // La rata vale € 3.400,00 ma solo € 2.400,00 sono spesa: il resto è pregresso assorbito.
    // Sommando l'importo grezzo il piano sembrerebbe coprire di più del dovuto.
    $s = scenarioAllineamento(240000);
    conQuota($s, importo: 340000, quotaPura: 240000, saldoUsato: 100000);

    expect(verdetto($s))->toBeFalse();
});

test('un saldo SOLIDALE non altera più il verdetto', function () {
    // È il caso che teneva acceso il badge sul piano 207 di Demo KM: `anagrafica_id` nullo,
    // quindi invisibile a qualunque aggregazione per persona. Con la componente pura non
    // entra proprio nel conto, e il piano risulta allineato come dev'essere.
    $s = scenarioAllineamento(240000);
    conQuota($s, importo: 240000, quotaPura: 240000);

    Saldo::create([
        'condominio_id'  => $s->condominio->id,
        'esercizio_id'   => $s->esercizio->id,
        'gestione_id'    => $s->gestione->id,
        'anagrafica_id'  => null,          // ← il solidale
        'immobile_id'    => $s->immobile->id,
        'saldo_iniziale' => 120000,
    ]);

    expect(verdetto($s))->toBeFalse();
});

test('la tolleranza è zero: un centesimo di scarto è disallineamento', function () {
    // La vecchia pagina tollerava fino a € 2,00 e sarebbe rimasta verde. I dati dicono che la
    // distribuzione penny-perfect somma esatta ai capitoli, quindi uno scarto è sempre un
    // segnale — e una tolleranza che non scatta mai può solo nascondere.
    $s = scenarioAllineamento(240000);
    conQuota($s, importo: 239999, quotaPura: 239999);

    expect(verdetto($s))->toBeTrue();
});

test('un piano senza rate non è disallineato: non è ancora stato generato', function () {
    $s = scenarioAllineamento(240000);

    expect(verdetto($s))->toBeFalse();
});
