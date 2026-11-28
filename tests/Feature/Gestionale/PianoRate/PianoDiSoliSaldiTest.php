<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un piano di soli saldi iniziali: la gestione «CONSUNTIVO 2025» portata da un altro gestionale non ha voci, solo i saldi (€ 332,01). Un saldo si
 * incassa solo dentro una rata, e la generazione si fermava prima che i saldi entrassero, con il messaggio sui millesimi. Ora nasce una rata da € 332,01
 * e il saldo resta legato al piano; l'emissione la porta ai crediti verso i condòmini, e l'incasso la chiude. Senza voci e senza saldi il messaggio non
 * parla più di millesimi.
 *
 * La pagina dei saldi offre «Rendi incassabili questi saldi» solo con saldi non a zero che nessun piano tiene, e porta alla creazione già compilata.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** «CONSUNTIVO 2025» senza voci, nell'esercizio 2026, con i saldi dati (unità => centesimi) per Ugo (A), Bice (C) e Rossi (5). */
function b15ssScenario(array $saldi = ['A' => 21000, 'C' => 8750, '5' => 3451]): array
{
    $s = b52tCondominio();
    $s['e'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $s['g'] = $s['c']->gestioni()->create(['nome' => 'CONSUNTIVO 2025', 'tipo' => 'ordinaria', 'attiva' => true, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $s['g']->esercizi()->attach($s['e']->id);
    $s['pc'] = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti CONSUNTIVO 2025']);
    foreach (['A' => 'ugo', 'C' => 'bice', '5' => 'rossi'] as $sigla => $chi) {
        b52tUnita($s, $sigla, $chi, $s['tabella'], 100.0);
    }
    foreach ($saldi as $sigla => $cents) {
        $chi = ['A' => 'ugo', 'C' => 'bice', '5' => 'rossi'][$sigla];
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$sigla]->id,
            'gestione_id' => $s['g']->id, 'saldo_iniziale' => $cents, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    return $s;
}

function b15ssCrea($test, array $s, string $metodo = 'prima_rata')
{
    session()->forget('errors');

    return $test->actingAs($test->user)->from('/nuovo-piano')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Saldi iniziali CONSUNTIVO 2025', 'metodo_distribuzione' => $metodo,
        'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-02-05', 'genera_subito' => true,
        'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [], 'accetta_destinatari' => true, 'nota_destinatari' => 'Saldi dal gestionale di prima',
    ]);
}

function b15ssMessaggio(): ?string
{
    return session('message')['message'] ?? (session('errors')?->getBag('default')->first() ?: null);
}

it('nasce una rata da € 332,01 e i saldi restano legati al piano, senza rate a zero', function () {
    $s = b15ssScenario();

    b15ssCrea($this, $s)->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->first();

    expect($piano, (string) b15ssMessaggio())->not->toBeNull();
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->get(['numero_rata', 'importo_totale']);
    expect($rate->map(fn ($r) => [(int) $r->numero_rata, (int) $r->importo_totale])->all())->toBe([[1, 33201]])
        ->and(Saldo::where('gestione_id', $s['g']->id)->whereNull('piano_rate_id')->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo'))->toBe(33201);
});

it('con «Rata 0» nasce la sola rata 0, senza la rata 1 a zero', function () {
    $s = b15ssScenario();

    b15ssCrea($this, $s, 'rata_zero')->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->first();

    expect($piano, (string) b15ssMessaggio())->not->toBeNull();
    expect(DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->get(['numero_rata', 'importo_totale'])
        ->map(fn ($r) => [(int) $r->numero_rata, (int) $r->importo_totale])->all())->toBe([[0, 33201]]);
});

it('senza voci e senza saldi la creazione si ferma con un messaggio che non parla di millesimi', function () {
    $s = b15ssScenario([]);

    b15ssCrea($this, $s)->assertRedirect();

    expect(PianoRate::where('gestione_id', $s['g']->id)->count())->toBe(0)
        ->and(b15ssMessaggio())->not->toContain('millesim')
        ->and(b15ssMessaggio())->toContain('non ha voci da ripartire né saldi da incassare');
});

it('la catena: il piano dei saldi si approva, si emette e si incassa; la quota è pagata', function () {
    $s = b15ssScenario(['A' => 33201]);
    b15ssCrea($this, $s)->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->sole();

    b52tApprova($this, $s, $piano);
    b52tEmetti($this, $s, $piano, 1, false);
    $quota = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->select('rate_quote.*')->first();
    expect($quota->scrittura_contabile_id)->not->toBeNull();
    // La riga dello stato patrimoniale: l'emissione scrive i crediti verso i condòmini contro le passate gestioni, per € 332,01.
    $righe = DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->where('righe_scritture.scrittura_id', $quota->scrittura_contabile_id)->get(['conti_contabili.ruolo', 'righe_scritture.tipo_riga', 'righe_scritture.importo'])
        ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo])->sortBy(fn ($r) => $r[0])->values()->all();
    expect($righe)->toBe([['crediti_condomini', 'dare', 33201], ['passate_gestioni', 'avere', 33201]]);

    b52tIncasso($this, $s, 'ugo', [$quota->id => 33201], 33201);

    $dopo = DB::table('rate_quote')->where('id', $quota->id)->first();
    expect((int) $dopo->importo_pagato)->toBe(33201);
});

it('la pagina dei saldi offre «Rendi incassabili» con i saldi liberi della gestione, e non più dopo che un piano li tiene', function () {
    $s = b15ssScenario();
    $props = fn () => $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];

    expect($props()['saldi_incassabili'] ?? null)->toBe([['gestione_id' => $s['g']->id, 'gestione' => 'CONSUNTIVO 2025', 'numero' => 3, 'totale' => 33201]]);

    b15ssCrea($this, $s)->assertRedirect();
    expect($props()['saldi_incassabili'] ?? null)->toBe([]);
});

it('la creazione aperta da «Rendi incassabili» arriva già compilata: la gestione, il nome, una rata, la prima rata ordinaria', function () {
    $s = b15ssScenario();

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.create', [$s['c'], $s['e'], 'origine' => 'saldi', 'gestione_id' => $s['g']->id]))
        ->assertOk()->viewData('page')['props'];

    expect($props['precompilato'] ?? null)->toBe(['origine' => 'saldi', 'gestione_id' => $s['g']->id, 'nome' => 'Saldi iniziali CONSUNTIVO 2025', 'numero_rate' => 1,
        'metodo_distribuzione' => 'prima_rata', 'totale' => 33201]);
});

it('il piano di soli saldi non ferma il piano con le voci che viene dopo sulla stessa gestione', function () {
    $s = b15ssScenario();
    b15ssCrea($this, $s)->assertRedirect();
    $voce = b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);

    session()->forget('errors');
    $this->actingAs($this->user)->from('/nuovo-piano')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-03-05', 'genera_subito' => true,
        'capitoli_ids' => [$voce->id], 'capitoli_config' => [['id' => $voce->id, 'importo' => '600,00', 'note' => '']], 'competenze_capitoli' => [],
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertRedirect();

    expect(b15ssMessaggio())->toBeNull();
    expect(PianoRate::where('gestione_id', $s['g']->id)->where('nome', 'Preventivo 2026')->exists(), (string) b15ssMessaggio())->toBeTrue();
});

it('«Ricalcola» del piano di soli saldi, dopo che la gestione ha una voce, chiede ancora i soli saldi: nessuna voce nella sua riga', function () {
    $s = b15ssScenario();
    b15ssCrea($this, $s)->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->sole();
    b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo del piano nella prova'])->assertRedirect();

    // A mano: i tre saldi, € 210,00 + € 87,50 + € 34,51 = € 332,01; le Pulizie (€ 600,00) restano libere per un altro piano.
    expect(session('message')['type'] ?? null, (string) b15ssMessaggio())->toBe('success')
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo'))->toBe(33201)
        ->and(Saldo::where('gestione_id', $s['g']->id)->where('piano_rate_id', $piano->id)->count())->toBe(3);
});

/** La creazione aperta da «Rendi incassabili questi saldi»: il modulo manda `origine=saldi`, come la pagina compilata. */
function b15ssDalPulsante($test, array $s, array $extra = [])
{
    session()->forget(['errors', 'message']);

    return $test->actingAs($test->user)->from('/nuovo-piano')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), array_merge([
        'tipo' => 'ordinario', 'origine' => 'saldi', 'gestione_id' => $s['g']->id, 'nome' => 'Saldi iniziali CONSUNTIVO 2025', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-02-05', 'genera_subito' => true,
        'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [], 'accetta_destinatari' => true, 'nota_destinatari' => 'Saldi dal gestionale di prima',
    ], $extra));
}

it('su una gestione con una voce libera, il piano nato da «Rendi incassabili» chiede i soli saldi: la voce resta libera', function () {
    $s = b15ssScenario();
    b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);

    b15ssDalPulsante($this, $s)->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->first();

    // A mano: € 210,00 + € 87,50 + € 34,51 = € 332,01; le Pulizie (€ 600,00) non entrano.
    expect($piano, (string) b15ssMessaggio())->not->toBeNull()
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo'))->toBe(33201)
        ->and(Saldo::where('gestione_id', $s['g']->id)->where('piano_rate_id', $piano->id)->count())->toBe(3)
        ->and($piano->fresh()->eDiSoliSaldi())->toBeTrue();
});

it('le voci mandate insieme a «Rendi incassabili» non entrano: il piano dei saldi non prende voci', function () {
    $s = b15ssScenario();
    $voce = b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);

    b15ssDalPulsante($this, $s, ['capitoli_ids' => [$voce->id], 'capitoli_config' => [['id' => $voce->id, 'importo' => '600,00', 'note' => '']]])->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->first();

    expect($piano, (string) b15ssMessaggio())->not->toBeNull()
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo'))->toBe(33201);
});

it('con le voci già tutte in un altro piano, «Rendi incassabili» crea lo stesso il piano dei saldi', function () {
    $s = b15ssScenario();
    $voce = b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);
    session()->forget('errors');
    $this->actingAs($this->user)->from('/nuovo-piano')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-03-05', 'genera_subito' => false,
        'capitoli_ids' => [$voce->id], 'capitoli_config' => [['id' => $voce->id, 'importo' => '600,00', 'note' => '']], 'competenze_capitoli' => [],
    ])->assertRedirect();
    expect(PianoRate::where('nome', 'Preventivo 2026')->exists(), (string) b15ssMessaggio())->toBeTrue();

    b15ssDalPulsante($this, $s)->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->where('nome', 'Saldi iniziali CONSUNTIVO 2025')->first();

    expect($piano, (string) b15ssMessaggio())->not->toBeNull()
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo'))->toBe(33201);
});

it('«Rendi incassabili» senza «Genera subito» genera lo stesso: il piano dei saldi nasce con la sua rata, senza voci', function () {
    $s = b15ssScenario();
    b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);

    b15ssDalPulsante($this, $s, ['genera_subito' => false])->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->first();

    expect($piano, (string) b15ssMessaggio())->not->toBeNull()
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo'))->toBe(33201);
});

it('«Rendi incassabili» su una gestione senza saldi liberi non crea niente: il server controlla l\'origine', function () {
    $s = b15ssScenario([]);
    b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);

    b15ssDalPulsante($this, $s)->assertRedirect();

    expect(PianoRate::where('gestione_id', $s['g']->id)->count())->toBe(0)
        ->and(b15ssMessaggio())->toContain('non ha saldi iniziali fuori da un piano rate');
});

it('«Ricalcola» del piano nato da «Rendi incassabili» su una gestione con voci resta di soli saldi', function () {
    $s = b15ssScenario();
    b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);
    b15ssDalPulsante($this, $s)->assertRedirect();
    $piano = PianoRate::where('gestione_id', $s['g']->id)->sole();

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo del piano nella prova'])->assertRedirect();

    expect(session('message')['type'] ?? null, (string) b15ssMessaggio())->toBe('success')
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo'))->toBe(33201);
});

it('la pagina dei saldi non conta i saldi col lucchetto senza piano: la generazione non li prende', function () {
    $s = b15ssScenario();
    Saldo::where('gestione_id', $s['g']->id)->where('immobile_id', $s['unita']['5']->id)->update(['is_applicato' => true]);

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];

    // A mano: restano € 210,00 + € 87,50 = € 297,50, due saldi; il saldo di Rossi (€ 34,51) ha il lucchetto e nessun piano lo prenderebbe.
    expect($props['saldi_incassabili'] ?? null)->toBe([['gestione_id' => $s['g']->id, 'gestione' => 'CONSUNTIVO 2025', 'numero' => 2, 'totale' => 29750]]);
});

it('il piano di soli saldi non compare fra i piani che cambiano per una voce libera nata dopo di lui: il confronto lo rilegge di soli saldi', function () {
    $s = b15ssScenario();
    b15ssDalPulsante($this, $s)->assertRedirect();
    b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);

    expect(app(\App\Services\Gestionale\PianiCheCambiano::class)->perUnita($s['unita']['A']->fresh()))->toBe([]);
});

it('il dettaglio del riparto del piano di soli saldi non mette la voce libera nata dopo di lui', function () {
    $s = b15ssScenario();
    b15ssDalPulsante($this, $s)->assertRedirect();
    $voce = b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']);
    $piano = PianoRate::where('gestione_id', $s['g']->id)->sole();

    $righe = \App\Services\Riparto\DettaglioRiparto::perPiano($piano->fresh())['righe'];

    expect(collect($righe)->filter(fn ($r) => (int) ($r['conto_id'] ?? 0) === (int) $voce->id)->count())->toBe(0);
});
