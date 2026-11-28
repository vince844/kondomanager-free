<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il capitolo nel piano senza una cifra (la copia nulla) vale ciò che chiede il motore: il suo importo più i suoi sottoconti, a ogni livello. Lo scenario:
 * «Manutenzione» (capitolo, € 0,00) con «Ascensore» € 600,00 e «Rimborso» € 100,00, accanto a «Pulizia scale» € 300,00, tutti e due nel piano senza cifra.
 * Il motore chiede € 1.000,00; la pagina, la riga dell'elenco e l'atteso del badge (che legge anche il cruscotto) dicevano € 300,00.
 *
 * «Sposta spesa» verso un capitolo senza cifra, o da lui, si ferma finché non si scrive la cifra: prima la copia diventava lo spostamento (€ 100,00) e
 * € 700,00 uscivano dal piano in silenzio. Con le tabelle sul capitolo è lo stesso (il motore scende comunque ai sottoconti).
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const B15CN_RIFIUTO = 'Il capitolo «Manutenzione» nel piano non ha una cifra: vale i suoi sottoconti (€ 700,00). Il piano non ha un campo per scriverla: togli il capitolo dal '
    . 'piano («Rimuovi voce e ricalcola») e rimettilo con «Sincronizza», che gli scrive la somma dei sottoconti; poi sposta.';

/** «Manutenzione» con i due sottoconti e «Pulizia scale», nel piano tutti e due senza cifra. `$tabelleSulCapitolo`: il capitolo ha la sua tabella. */
function b15cnScenario($test, bool $tabelleSulCapitolo = false): array
{
    $s = b53snCapitolo($test);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => 10000]);
    if ($tabelleSulCapitolo) {
        $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $s['capitolo']->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    $s['pulizia'] = b52tVoce($test, $s, 'Pulizia scale', 'spesa', '300,00', $s['tabella']);
    $voci = [$s['capitolo']->id, $s['pulizia']->id];
    $s['piano'] = b52tPiano($test, $s, ['capitoli_ids' => $voci,
        'capitoli_config' => array_map(fn ($id) => ['id' => $id, 'importo' => '', 'note' => ''], $voci)], 'Ordinario 2026');
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->whereNotNull('importo')->count())->toBe(0);

    return $s;
}

function b15cnMotore(array $s): int
{
    return array_sum(array_map('abs', b53snRipartito($s, $s['piano'])));
}

function b15cnSposta($test, array $s, int $da, int $a)
{
    return $test->actingAs($test->user)->from('/pagina-del-piano')->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $s['piano']]), [
        'source_id' => $da, 'destination_id' => $a, 'amount' => '100.00', 'reason' => 'Prova',
    ]);
}

it('la pagina, la riga dell\'elenco e l\'atteso del badge dicono il totale del motore: € 1.000,00, e la riga del capitolo € 700,00', function (bool $tabelle) {
    $s = b15cnScenario($this, $tabelle);

    expect(b15cnMotore($s))->toBe(100000);

    $props = b53snProps($this, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]);
    $pagina = json_decode(json_encode($props['pianoRate']), true);
    expect($pagina['totale_spese'])->toBe(100000)
        ->and($pagina['totale_da_ripartire'])->toBe(100000)
        ->and(collect($pagina['capitoli'])->firstWhere('nome', 'Manutenzione')['importo'])->toBe(70000)
        ->and($props['allineamento']['atteso_cents'])->toBe(100000)
        ->and($props['disallineato'])->toBeFalse();

    $riga = collect(json_decode(json_encode(b53snProps($this, 'admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['e']])['pianiRate']), true))
        ->firstWhere('id', $s['piano']->id);
    expect($riga['totale_spese'])->toBe(100000);

    // Il cruscotto legge lo stesso numero.
    expect(app(App\Services\PianoRateQuoteService::class)->totaleAttesoCents($s['piano']->fresh()))->toBe(100000);
})->with(['senza tabelle sul capitolo' => [false], 'con le tabelle sul capitolo' => [true]]);

it('«Sposta spesa» verso il capitolo senza cifra si ferma con la frase, e il motore resta a € 1.000,00', function (bool $tabelle) {
    $s = b15cnScenario($this, $tabelle);

    b15cnSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id)->assertRedirect('/pagina-del-piano');

    expect(b53snErrori()['destination_id'] ?? [])->toBe([B15CN_RIFIUTO])
        ->and(DB::table('budget_movements')->count())->toBe(0)
        ->and(b15cnMotore($s))->toBe(100000);
})->with(['senza tabelle sul capitolo' => [false], 'con le tabelle sul capitolo' => [true]]);

it('«Sposta spesa» dal capitolo senza cifra si ferma con la stessa frase, sulla sorgente', function () {
    $s = b15cnScenario($this);

    b15cnSposta($this, $s, (int) $s['capitolo']->id, (int) $s['pulizia']->id)->assertRedirect('/pagina-del-piano');

    expect(b53snErrori()['source_id'] ?? [])->toBe([B15CN_RIFIUTO])
        ->and(DB::table('budget_movements')->count())->toBe(0)
        ->and(b15cnMotore($s))->toBe(100000);
});

it('controprova: con la cifra scritta nel capitolo lo spostamento passa e il totale resta € 1.000,00', function () {
    $s = b15cnScenario($this);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['capitolo']->id)->update(['importo' => 70000]);

    b15cnSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    expect(b15cnMotore($s))->toBe(100000);
});

/** I nomi delle voci che la pagina del piano offre come destinazione di «Sposta spesa». */
function b15cnDestinazioni($test, array $s): array
{
    return collect(json_decode(json_encode(b53snProps($test, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']])['destinations']), true))
        ->pluck('nome')->all();
}

/** Le copie del piano: conto => centesimi (nulla se il piano legge l'importo della voce). */
function b15cnCopie(array $s): array
{
    return DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->orderBy('conto_id')->pluck('importo', 'conto_id')
        ->map(fn ($v) => $v === null ? null : (int) $v)->all();
}

it('una voce senza tabelle e senza sottoconti non riceve budget: la pagina non la offre e lo spostamento si ferma, con le copie di prima', function () {
    $s = b15cnScenario($this);
    $giardino = b52tVoce($this, $s, 'Giardino', 'spesa', '50,00', $s['tabella']);
    $legami = DB::table('conto_tabella_millesimale')->where('conto_id', $giardino->id)->pluck('id');
    DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', $legami)->delete();
    DB::table('conto_tabella_millesimale')->whereIn('id', $legami)->delete();

    // Prima la sorgente con una cifra: la Pulizia scale a € 300,00.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizia']->id)->update(['importo' => 30000]);
    $prima = b15cnCopie($s);
    expect(b15cnDestinazioni($this, $s))->not->toContain('Giardino');

    b15cnSposta($this, $s, $s['pulizia']->id, $giardino->id)->assertSessionHasErrors(['destination_id' => 'La voce «Giardino» non ha tabelle millesimali né sottoconti: il budget spostato su di lei non lo pagherebbe nessuno. Collega prima una tabella alla voce nel piano dei conti, poi sposta.']);
    expect(b15cnCopie($s))->toBe($prima)
        ->and(DB::table('budget_movements')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0);
});

it('controprova — la stessa voce con la sua tabella si offre e riceve il budget', function () {
    $s = b15cnScenario($this);
    $giardino = b52tVoce($this, $s, 'Giardino', 'spesa', '50,00', $s['tabella']);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizia']->id)->update(['importo' => 30000]);
    expect(b15cnDestinazioni($this, $s))->toContain('Giardino');

    b15cnSposta($this, $s, $s['pulizia']->id, $giardino->id)->assertSessionHasNoErrors();
    expect(b15cnCopie($s)[$giardino->id] ?? null)->toBe(10000);
});

it('la strada che la frase dice c\'è: tolto il capitolo dal piano e rimesso con «Ricalcola» fra le voci libere, ha la sua cifra (€ 700,00) e lo spostamento passa', function () {
    $s = b15cnScenario($this);
    $presa = ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo del piano nella prova'];

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->from('/pagina-del-piano')->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $s['piano'], $s['capitolo']->id]), $presa)
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(session('message')['type'] ?? null, (string) (session('message')['message'] ?? ''))->toBe('success');
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->from('/pagina-del-piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]),
        $presa + ['orphan_ids' => [$s['capitolo']->id]])->assertSessionHasNoErrors()->assertRedirect();
    expect(session('message')['type'] ?? null, (string) (session('message')['message'] ?? ''))->toBe('success');

    // A mano: «Ascensore» € 600,00 + «Rimborso» € 100,00 = € 700,00, la cifra del capitolo; il piano resta € 1.000,00.
    expect(b15cnCopie($s)[$s['capitolo']->id] ?? 'manca')->toBe(70000)
        ->and(b15cnMotore($s))->toBe(100000);
    b15cnSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    expect(DB::table('budget_movements')->count())->toBe(1)->and(b15cnMotore($s))->toBe(100000);
});
