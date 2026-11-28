<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La rata mista (il debito di prima spalmato nella prima rata) in «Rate scadute» e nella stampa dei crediti per anzianità: la parte di prima ancora da pagare
 * sta nella fascia della sua origine, come nel § 10 del rendiconto, e il resto nella fascia della rata. Prima «Rate scadute» contava la rata intera con
 * l'età della rata, e le fasce non coincidevano con il § 10.
 *
 * Il caso a mano: Ugo deve € 300,00 del 2025 dentro la prima rata (€ 420,00 con € 120,00 del 2026); la rata scade il 05/01/2026. Al 20/02/2026 la rata è
 * in ritardo di 46 giorni (fascia 31–90): € 300,00 del 2025 (un esercizio: nessun numero di giorni) oltre i 90, € 120,00 fra 31 e 90. Dopo € 200,00 versati
 * (pagano prima il debito più vecchio): € 100,00 oltre i 90, € 120,00 fra 31 e 90.
 */

use App\Models\Esercizio;
use App\Services\Gestionale\PosizioniDeiCondomini;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function rmfScenario($test): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 1, 'prima_rata', [['ugo', 'A', 30000]], 12000);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    DB::table('saldi')->where('condominio_id', $s['c']->id)->update(['origine_dichiarata' => 'esercizio', 'esercizio_origine_id' => $e2025->id]);
    lcdEmetti($test, $s, [1], '2026-01-02');

    return $s;
}

/** «Rate scadute» dalla rotta, alla data: la riga di Ugo. */
function rmfRateScadute($test, array $s, string $alla): array
{
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'alla' => $alla]))->assertOk()->viewData('page')['props'];

    return collect($props['righe']['data'])->firstWhere('nome', 'Ugo');
}

it('«Rate scadute» mette la parte di prima della rata mista nella fascia della sua origine, come il § 10, e la rata lo dice', function () {
    $s = rmfScenario($this);
    $this->travelTo('2026-02-20 10:00:00');

    $riga = rmfRateScadute($this, $s, '2026-02-20');
    $dieci = app(PosizioniDeiCondomini::class)->crediti($s['c'], $s['e'], 'scaduto');
    expect($riga['importo'])->toBe(42000)
        ->and($riga['fasce'])->toBe(['1_30' => 0, '31_90' => 12000, 'oltre_90' => 30000])
        ->and($riga['fasce'])->toBe($dieci['totali']['fasce'])
        ->and($riga['anzianita_non_nota'])->toBeTrue()
        ->and($riga['rate'][0]['di_prima'])->toBe(['importo' => 30000, 'etichetta' => 'pregresso dell\'esercizio 2025'])
        // Il segno «la sua età è quella della rata» non vale più per la parte di prima: resta per un credito di prima, che non si divide.
        ->and($riga['rate'][0]['pregresso_nella_rata'])->toBeFalse()
        ->and($riga['con_pregresso'])->toBeTrue();

    // € 200,00 versati pagano prima il debito del 2025.
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 20000], '2026-02-20');
    $riga = rmfRateScadute($this, $s, '2026-02-20');
    expect($riga['fasce'])->toBe(['1_30' => 0, '31_90' => 12000, 'oltre_90' => 10000])
        ->and($riga['rate'][0]['di_prima'])->toBe(['importo' => 10000, 'etichetta' => 'pregresso dell\'esercizio 2025'])
        ->and($riga['fasce'])->toBe(app(PosizioniDeiCondomini::class)->crediti($s['c'], $s['e'], 'scaduto')['totali']['fasce']);
});

it('la stampa dei crediti per anzianità dice che la riga è la parte di prima dentro la rata, pagata per prima', function () {
    $s = rmfScenario($this);
    $this->travelTo('2026-02-20 10:00:00');
    $c = app(PosizioniDeiCondomini::class)->crediti($s['c'], $s['e'], 'scaduto');
    $html = html_entity_decode(view('pdf.gestionale.rendiconto_crediti', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'crediti' => $c])->render(), ENT_QUOTES);

    expect(collect($c['righe'])->map(fn ($r) => [$r['importo'], $r['di_prima_nella_rata']])->all())->toBe([[30000, true], [12000, false]])
        ->and(substr_count($html, PosizioniDeiCondomini::NOTA_DI_PRIMA_NELLA_RATA))->toBe(1);
});
