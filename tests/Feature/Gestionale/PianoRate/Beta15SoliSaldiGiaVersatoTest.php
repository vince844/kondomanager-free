<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il piano di soli saldi e la guardia del già versato.
 *
 * Il caso: la gestione ordinaria usata nel 2026 e nel 2027. Nel 2026 nasce «Saldi iniziali», un piano di soli saldi (€ 210,00 di Ugo e € 87,50 di Bice),
 * quando la gestione non ha ancora voci; poi arrivano «Spese generali» (€ 1.200,00) e «Pulizie» (€ 200,00), e Ugo ha € 100,00 già versati sulle Pulizie
 * che valgono per tutti gli esercizi. Il piano del 2027 chiede le Pulizie per € 200,00. La guardia del già versato (`CalcoloQuoteService::rifiutoDelGiaVersato`)
 * trattava il piano di soli saldi come un piano senza righe, che prende le voci che nessun altro piano del suo esercizio ha: contava € 200,00 di Pulizie
 * chieste da «Saldi iniziali» e fermava il piano del 2027 (€ 400,00 sul budget di € 200,00). Il piano di soli saldi non chiede nessuna voce (`PianoRate::eDiSoliSaldi`):
 * la guardia non lo conta fra gli altri piani, e non lo ferma quando guarda lui.
 *
 * Fonte dell'atteso: i conti a mano accanto a ogni test.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite).
 */

use App\Models\Esercizio;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Services\CalcoloQuoteService;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
    $this->travelTo('2027-02-10 10:00:00');
});

/**
 * La gestione sul 2026 e sul 2027, «Saldi iniziali» del 2026 generato senza voci, poi le due voci e il già versato di Ugo sulle Pulizie per tutti gli esercizi.
 * Con `$p27PrimaDelGiaVersato` il piano del 2027 sulle Pulizie nasce prima del già versato: c'è già quando la guardia guarda «Saldi iniziali».
 */
function b15sgvScenario($test, bool $p27PrimaDelGiaVersato = false): array
{
    $s = b52tCondominio();
    $s['es']['2026'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $s['es']['2027'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $s['g'] = app(CondominioService::class)->createDefaultGestione($s['c'], $s['es']['2026']);
    app(CondominioService::class)->createDefaultGestione($s['c'], $s['es']['2027']);
    $s['e'] = $s['es']['2026'];
    $s['pc'] = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    foreach (['A' => ['ugo', 21000], 'C' => ['bice', 8750]] as $sigla => [$chi, $cents]) {
        Saldo::create(['esercizio_id' => $s['es']['2026']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$sigla]->id,
            'gestione_id' => $s['g']->id, 'saldo_iniziale' => $cents, 'origine' => 'manuale', 'is_applicato' => false]);
    }
    $s['saldi'] = b15sgvCrea($test, $s, '2026', 'Saldi iniziali', [], 1);
    expect($s['saldi']->eDiSoliSaldi())->toBeTrue();

    $s['spesa'] = b52tVoce($test, $s, 'Spese generali', 'spesa', '1.200,00', $s['tabella']);
    $s['pulizie'] = b52tVoce($test, $s, 'Pulizie', 'spesa', '200,00', $s['tabella']);
    if ($p27PrimaDelGiaVersato) {
        $s['p27'] = b15sgvCrea($test, $s, '2027', 'Preventivo 2027', ['pulizie' => '200,00']);
        expect($s['p27'], (string) b15sgvErrore())->not->toBeNull();
    }
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $s['pulizie']->id,
        'immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'importo_cents' => 10000, 'natura' => 'fondo_vincolato',
        'origine' => 'migrazione', 'esercizio_id' => null]);

    return $s;
}

/** Un piano creato dalla pagina dell'esercizio `$anno` e generato subito: `$voci` = [nome della voce nello scenario => euro]. Restituisce il piano, o null con l'errore in sessione. */
function b15sgvCrea($test, array $s, string $anno, string $nome, array $voci, int $rate = 2): ?PianoRate
{
    session()->forget(['message', 'errors']);
    $test->actingAs($test->user)->from('/nuovo-piano')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es'][$anno]]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => $rate,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => "{$anno}-03-05", 'genera_subito' => true,
        'capitoli_ids' => array_map(fn ($v) => $s[$v]->id, array_keys($voci)),
        'capitoli_config' => array_map(fn ($v, $euro) => ['id' => $s[$v]->id, 'importo' => $euro, 'note' => ''], array_keys($voci), array_values($voci)),
        'competenze_capitoli' => [], 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertRedirect();

    return PianoRate::where('gestione_id', $s['g']->id)->where('nome', $nome)->first();
}

function b15sgvErrore(): ?string
{
    $m = session('message');

    return ($m['type'] ?? null) === 'error' ? ($m['message'] ?? null) : (session('errors')?->getBag('default')->first() ?: null);
}

function b15sgvQuote(PianoRate $piano): array
{
    return b52tSommeQuote($piano);
}

it('gli altri piani: il piano di soli saldi del 2026 non chiede le Pulizie, e il piano del 2027 nasce e toglie a Ugo i suoi € 100,00', function () {
    $s = b15sgvScenario($this);

    $p27 = b15sgvCrea($this, $s, '2027', 'Preventivo 2027', ['pulizie' => '200,00']);

    // A mano: Pulizie € 200,00 a metà, € 100,00 a testa; a Ugo si tolgono i € 100,00 già versati: Ugo € 0,00, Bice € 100,00.
    expect(b15sgvErrore())->toBeNull()
        ->and($p27)->not->toBeNull()
        ->and(b15sgvQuote($p27))->toBe(['Bice' => 10000, 'Ugo' => 0]);
});

it('questo piano: la guardia che guarda il piano di soli saldi non lo ferma, perché non chiede le Pulizie', function () {
    $s = b15sgvScenario($this, p27PrimaDelGiaVersato: true);

    // La lettura dell'anteprima delle stampe, che dice se la generazione si fermerebbe per il già versato.
    $motore = app(CalcoloQuoteService::class);
    $motore->calcolaPerGestione($s['g'], $s['saldi']->fresh(), soloLettura: true, guardiaSenzaFermarsi: true);

    expect($motore->getNonSiGenera())->toBeNull();
});

it('«Ricalcola» del piano di soli saldi, con le voci e il già versato: chiede ancora i soli saldi, € 210,00 e € 87,50, e il piano del 2027 non cambia', function () {
    $s = b15sgvScenario($this, p27PrimaDelGiaVersato: true);
    $p27 = $s['p27'];
    // Il piano del 2027 è nato prima del già versato: € 100,00 a testa.
    $p27Prima = b15sgvQuote($p27->fresh());
    expect($p27Prima)->toBe(['Bice' => 10000, 'Ugo' => 10000]);

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es']['2026'], $s['saldi']]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo del piano nella prova'])->assertRedirect();

    expect((string) b15sgvErrore())->toBe('')->and(session('message')['type'] ?? null)->toBe('success')
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['saldi']->id)->count())->toBe(0)
        ->and(b15sgvQuote($s['saldi']->fresh()))->toBe(['Bice' => 8750, 'Ugo' => 21000])
        ->and(b15sgvQuote($p27->fresh()))->toBe($p27Prima);
});

it('«Ricalcola» di un piano senza righe di una versione di prima, nel 2027: il piano di soli saldi del 2026 non è un piano che gli contende le voci', function () {
    $s = b15sgvScenario($this);
    $p27 = b15sgvCrea($this, $s, '2027', 'Preventivo 2027', []);
    expect($p27, (string) b15sgvErrore())->not->toBeNull();
    // La riga delle voci vuota, come la lasciavano le versioni di prima.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $p27->id)->delete();

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es']['2027'], $p27]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo del piano nella prova'])->assertRedirect();

    // A mano: «Spese generali» € 1.200,00 e «Pulizie» € 200,00 a metà, € 700,00 a testa; a Ugo si tolgono i € 100,00 già versati: Ugo € 600,00, Bice € 700,00.
    expect((string) b15sgvErrore())->toBe('')
        ->and(b15sgvQuote($p27->fresh()))->toBe(['Bice' => 70000, 'Ugo' => 60000]);
});

it('controprova — un piano senza righe che non è di soli saldi conta ancora fra gli altri: il secondo piano del 2027 sulle Pulizie si ferma', function () {
    $s = b15sgvScenario($this);
    // Il piano del 2026 senza voci scelte, nato dopo le voci: prende «Spese generali» e «Pulizie», e le chiede.
    $p26 = b15sgvCrea($this, $s, '2026', 'Preventivo 2026', []);
    expect($p26)->not->toBeNull()
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $p26->id)->pluck('conto_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe([$s['spesa']->id, $s['pulizie']->id]);

    // A mano: «Preventivo 2026» chiede già € 200,00 di Pulizie; con il piano del 2027 la somma è € 400,00 sul budget di € 200,00, e il già versato per tutti
    // gli esercizi si toglierebbe due volte.
    $p27 = b15sgvCrea($this, $s, '2027', 'Preventivo 2027', ['pulizie' => '200,00']);

    expect($p27)->toBeNull()
        ->and((string) b15sgvErrore())->toContain('Impossibile generare')->toContain('Pulizie');
});
