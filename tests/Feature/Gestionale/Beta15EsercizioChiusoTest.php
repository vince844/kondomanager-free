<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'unico esercizio messo su «Chiuso» e l'esercizio nuovo creato dopo.
 *
 * Con il solo esercizio chiuso, la registrazione di un incasso dava una pagina 500: l'azione vuole un esercizio e riceveva null. Ora torna al
 * modulo con il motivo, e non scrive niente. E l'esercizio creato dalla pagina nasceva senza nessuna gestione: ora la gestione ordinaria del
 * condominio ci si aggancia, come alla creazione del condominio, e il primo saldo iniziale ha dove andare. Il legame entra nel registro delle
 * modifiche, e l'esercizio chiuso non si tocca.
 *
 * Lo scenario è quello di `Beta52TotaliScenari.php`: le unità di Ugo e Bice, la gestione ordinaria sull'esercizio 2026, le voci e il piano.
 *
 * Che cosa non copre: le altre registrazioni senza un esercizio aperto; il modulo degli esercizi (lo stato «Chiuso» si sceglie ancora da lì);
 * i database diversi da sqlite.
 */

use App\Models\Esercizio;
use App\Models\Gestione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const B15EC_SENZA_ESERCIZIO = 'Non c\'è un esercizio aperto: riapri l\'esercizio o creane uno nuovo.';

/** Lo stato dell'esercizio dalla pagina degli esercizi. */
function b15ecStato($test, array $s, Esercizio $e, string $stato): void
{
    $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.update', [$s['c'], $e]), [
        'nome' => $e->nome, 'descrizione' => $e->nome, 'data_inizio' => $e->data_inizio->format('Y-m-d'), 'data_fine' => $e->data_fine->format('Y-m-d'),
        'stato' => $stato,
    ])->assertSessionHasNoErrors();
    expect($e->fresh()->stato)->toBe($stato);
}

/**
 * Un condominio senza gestioni, con il 2026 già chiuso: senza nessun esercizio il gestionale ne creerebbe uno da sé alla prima richiesta
 * (`EnsureCondominioHasEsercizio`), e la prova non misurerebbe la pagina «Nuovo esercizio».
 */
function b15ecSenzaGestioni(): array
{
    $s = b52tCondominio();
    $s['e'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'chiuso']);

    return $s;
}

/** L'esercizio 2027 dalla pagina «Nuovo esercizio». */
function b15ecCrea($test, array $s, string $stato = 'aperto', int $anno = 2027)
{
    session()->forget('message');

    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.store', [$s['c']]), [
        'nome' => "Esercizio {$anno}", 'descrizione' => "Anno {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => $stato,
    ]);
}

/** Le gestioni agganciate all'esercizio: tipo => id. */
function b15ecGestioni(int $esercizioId): array
{
    return DB::table('esercizio_gestione as eg')->join('gestioni as g', 'g.id', '=', 'eg.gestione_id')->where('eg.esercizio_id', $esercizioId)
        ->orderBy('g.id')->get(['g.tipo', 'g.id'])->map(fn ($r) => [$r->tipo, (int) $r->id])->all();
}

it('con il solo esercizio chiuso l\'incasso torna al modulo con il motivo, non dà una pagina 500 e non scrive niente', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);
    b52tEmetti($this, $s, $piano, 1, false);
    $quota = DB::table('rate_quote')->where('rata_id', b52tRata($piano, 1))->where('anagrafica_id', $s['persone']['ugo']->id)->first();
    $richiesta = [
        'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-05',
        'importo_totale' => number_format($quota->importo / 100, 2, '.', ''), 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => number_format($quota->importo / 100, 2, '.', '')]], 'descrizione' => 'Bonifico di Ugo',
    ];
    $incassi = fn () => DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->count();
    b15ecStato($this, $s, $s['e'], 'chiuso');

    $this->actingAs($this->user)->from('/nuovo-incasso')->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $richiesta)
        ->assertRedirect('/nuovo-incasso')
        ->assertSessionHasErrors(['esercizio' => B15EC_SENZA_ESERCIZIO]);

    expect($incassi())->toBe(0)
        ->and((int) DB::table('rate_quote')->where('id', $quota->id)->value('importo_pagato'))->toBe(0);

    // La controprova: con l'esercizio riaperto la stessa richiesta si registra.
    b15ecStato($this, $s, $s['e']->fresh(), 'aperto');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $richiesta)->assertRedirect()->assertSessionHasNoErrors();
    expect($incassi())->toBe(1);
});

it('l\'esercizio nuovo nasce con la gestione ordinaria del condominio agganciata, la pagina delle gestioni la mostra e il primo saldo iniziale si salva', function () {
    $s = b52tScenario($this);
    b15ecStato($this, $s, $s['e'], 'chiuso');
    $delChiuso = DB::table('esercizio_gestione')->where('esercizio_id', $s['e']->id)->get()->map(fn ($r) => (array) $r)->all();

    b15ecCrea($this, $s)->assertSessionHasNoErrors();
    expect(session('message')['type'] ?? null)->toBe('success');
    $nuovo = Esercizio::where('condominio_id', $s['c']->id)->where('nome', 'Esercizio 2027')->firstOrFail();

    expect(b15ecGestioni($nuovo->id))->toBe([['ordinaria', (int) $s['g']->id]])
        // La gestione è la stessa del 2026, non una seconda, e le righe dell'esercizio chiuso sono quelle di prima.
        ->and(Gestione::where('condominio_id', $s['c']->id)->count())->toBe(1)
        ->and(DB::table('esercizio_gestione')->where('esercizio_id', $s['e']->id)->get()->map(fn ($r) => (array) $r)->all())->toBe($delChiuso);

    // Il legame nel registro delle modifiche, come quando si aggancia dalla pagina della gestione.
    $riga = DB::table('registro_modifiche')->where('oggetto_tipo', \App\Services\RegistroModifiche\CatalogoDelRegistro::tipoDi($s['g']))
        ->where('oggetto_id', $s['g']->id)->orderByDesc('id')->first();
    $campo = collect(json_decode($riga?->campi ?? '[]', true))->firstWhere('chiave', 'esercizi');
    expect($riga?->azione)->toBe('modificata')
        ->and($campo['prima'] ?? null)->toBe('Esercizio 2026')
        ->and($campo['dopo'] ?? null)->toBe('Esercizio 2026, Esercizio 2027');

    $gestioni = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.gestioni.index', [$s['c'], $nuovo]))->assertOk()
        ->viewData('page')['props']['gestioni'];
    expect(array_column($gestioni, 'id'))->toBe([$s['g']->id]);

    // Il capo della catena: il primo saldo iniziale dell'anno nuovo.
    $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gestione_id' => b15ecGestioni($nuovo->id)[0][1], 'saldo_iniziale' => 5000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $saldo = DB::table('saldi')->where('condominio_id', $s['c']->id)->first();
    expect($saldo)->not->toBeNull()
        ->and((int) $saldo->esercizio_id)->toBe($nuovo->id)
        ->and((int) $saldo->gestione_id)->toBe((int) $s['g']->id)
        ->and((int) $saldo->saldo_iniziale)->toBe(5000);
});

it('un condominio senza gestione ordinaria la riceve con l\'esercizio nuovo, anche se l\'esercizio nasce chiuso', function (string $stato) {
    $s = b15ecSenzaGestioni();

    b15ecCrea($this, $s, $stato)->assertSessionHasNoErrors();
    $nuovo = Esercizio::where('condominio_id', $s['c']->id)->where('nome', 'Esercizio 2027')->firstOrFail();
    $ordinaria = Gestione::where('condominio_id', $s['c']->id)->where('tipo', 'ordinaria')->get();

    expect($ordinaria)->toHaveCount(1)
        ->and(b15ecGestioni($nuovo->id))->toBe([['ordinaria', $ordinaria[0]->id]]);
})->with(['aperto', 'chiuso']);

it('se la gestione non si può agganciare, l\'esercizio non resta creato a metà', function () {
    $s = b15ecSenzaGestioni();
    // Il nome della gestione ordinaria è già di una straordinaria: la creazione della gestione fallisce sull'indice unico.
    Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Gestione ordinaria', 'tipo' => 'straordinaria', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);

    b15ecCrea($this, $s);

    expect(session('message')['type'] ?? null)->toBe('error')
        ->and(Esercizio::where('condominio_id', $s['c']->id)->pluck('nome')->all())->toBe(['Esercizio 2026']);
});

it('con due gestioni ordinarie l\'esercizio nuovo aggancia la prima attiva, non una spenta, e il messaggio dice quale', function () {
    $s = b52tScenario($this);
    b15ecStato($this, $s, $s['e'], 'chiuso');
    $s['g']->forceFill(['attiva' => false, 'nome' => 'Ordinaria 2026'])->save();
    $attiva = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2027', 'tipo' => 'ordinaria', 'attiva' => true, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);

    b15ecCrea($this, $s)->assertRedirect()->assertSessionHasNoErrors();
    $nuovo = Esercizio::where('condominio_id', $s['c']->id)->where('nome', 'Esercizio 2027')->firstOrFail();

    expect(b15ecGestioni($nuovo->id))->toBe([['ordinaria', (int) $attiva->id]])
        ->and(session('message')['type'] ?? null)->toBe('success')
        ->and((string) (session('message')['message'] ?? ''))->toContain('La gestione «Ordinaria 2027» è agganciata all\'esercizio nuovo: è la prima gestione ordinaria attiva. «Ordinaria 2026» non è agganciata: se serve, la agganci dalla sua pagina.');
});

it('con due gestioni ordinarie attive l\'esercizio nuovo aggancia la prima, e con una sola il messaggio la nomina senza altro', function () {
    $s = b52tScenario($this);
    b15ecStato($this, $s, $s['e'], 'chiuso');
    $seconda = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria bis', 'tipo' => 'ordinaria', 'attiva' => true, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);

    b15ecCrea($this, $s)->assertRedirect()->assertSessionHasNoErrors();
    $nuovo = Esercizio::where('condominio_id', $s['c']->id)->where('nome', 'Esercizio 2027')->firstOrFail();
    expect(b15ecGestioni($nuovo->id))->toBe([['ordinaria', (int) $s['g']->id]])
        ->and((string) (session('message')['message'] ?? ''))->toContain('è la prima gestione ordinaria attiva. «Ordinaria bis» non è agganciata');

    $seconda->delete();
    b15ecCrea($this, $s, 'chiuso', 2028)->assertRedirect()->assertSessionHasNoErrors();
    $nome = (string) $s['g']->fresh()->nome;
    expect((string) (session('message')['message'] ?? ''))->toContain("La gestione «{$nome}» è agganciata all'esercizio nuovo.")
        ->not->toContain('prima gestione ordinaria attiva');
});
