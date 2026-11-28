<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Quanto chiede il piano integrativo dopo una fattura in sforo con la «Rata integrativa», su una voce con il già versato: la cifra che il modale
 * dello sforo deve dire.
 *
 * Lo scenario: Ugo (A) e Bice (C) a 500 millesimi; «Spese generali» € 1.200,00; il già versato di Ugo € 300,00 per il 2026; il «Preventivo 2026» sulla
 * voce generato prima della fattura (Ugo € 600,00 − € 300,00 = € 300,00, Bice € 600,00). Poi la fattura da € 6.100,00, con lo sforo a rata integrativa:
 * la voce sale a € 6.100,00. Poi l'«Integrativo 2026» chiede lo sforo, € 4.900,00.
 *
 * Le cifre a mano. Il motore toglie il già versato in proporzione: la quota del piano sulla voce è € 4.900,00 / € 6.100,00, e il già versato tolto
 * all'integrativo è € 300,00 × 4.900 / 6.100 = € 240,98. L'integrativo chiede € 4.900,00 − € 240,98 = € 4.659,02 (Ugo € 2.450,00 − € 240,98 =
 * € 2.209,02, Bice € 2.450,00), non € 5.800,00 (lo sforo meno tutto il già versato, la cifra che il modale diceva prima) né € 4.900,00. Il piano base
 * già generato aveva tolto tutti i € 300,00: insieme i due piani chiedono € 900,00 + € 4.659,02 = € 5.559,02, e la spesa meno il già versato è € 5.800,00.
 * I € 240,98 di differenza non li chiede nessuno finché il piano base non si ricalcola (la sonda lo registra; è un lavoro del motore, non del modale).
 *
 * Due scenari: il piano base con la voce a € 1.200,00 scritta, e con l'importo vuoto (la voce intera). Con l'importo vuoto, alzata la voce, il base
 * conta già la voce intera (€ 6.100,00): l'integrativo da € 4.900,00 si rifiuta, e ricalcolato il base chiede da solo € 6.100,00 − € 300,00 = € 5.800,00.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 4, 15)->setTime(10, 0));
    $this->user = be52Utente();
    b52tSqliteJson();
});

function idsScenario($test): array
{
    static $seq = 700;
    $seq++;
    $c = Condominio::factory()->create();
    app(CondominioService::class)->ensureDefaultConti($c);
    $banca = ContoContabile::where('condominio_id', $c->id)->where('ruolo', 'cassa')->first()
        ?? ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 1000000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $e = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($c, $e);

    $s = ['c' => $c, 'cassa' => $cassa, 'tabella' => $tabella, 'e' => $e, 'g' => $g, 'seq' => $seq, 'persone' => [], 'unita' => []];
    be52Unita($s, 'A', 'ugo', $tabella, 500.0);
    be52Unita($s, 'C', 'bice', $tabella, 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s['spesa'] = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');

    $test->actingAs($test->user)->from('/gia-versato')->put(route('admin.gestionale.contributi.update', [$c, $s['spesa']]), [
        'natura' => 'fondo_vincolato', 'vale_per' => $e->id,
        'righe' => [
            ['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gia_versato' => 30000],
            ['immobile_id' => $s['unita']['C']->id, 'anagrafica_id' => $s['persone']['bice']->id, 'gia_versato' => 0],
        ],
        'descrizione' => 'Accantonamento di prima', 'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA, 'cassa_id' => null,
    ])->assertSessionHasNoErrors();

    return $s;
}

function idsFattura($test, array $s): void
{
    \Illuminate\Support\Facades\Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, be52Fornitore(), $s['spesa']->id, 6100.00, [
        'esercizio_id' => $s['e']->id, 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => [
            'motivazione' => 'Sforamento della voce di prova', 'importo_sforo' => 490000, 'strategia_rientro' => 'rata_integrativa',
        ]],
    ], 'FT-2026-610000'))->assertSessionHasNoErrors();
    expect((int) Conto::find($s['spesa']->id)->importo)->toBe(610000);
}

/** Il piano dalla rotta di creazione; restituisce il messaggio d'errore, o null. */
function idsCrea($test, array $s, string $nome, string $importo): ?string
{
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1,
        'capitoli_config' => [['id' => $s['spesa']->id, 'importo' => $importo]],
    ])->assertSessionHasNoErrors();
    $m = session('message');

    return ($m['type'] ?? null) === 'error' ? $m['message'] : null;
}

/** Quanto il piano chiede a ognuno, sommando tutte le rate. */
function idsChiesto(array $s, string $nome): array
{
    $piano = PianoRate::where('condominio_id', $s['c']->id)->where('nome', $nome)->firstOrFail();

    return collect($s['persone'])->map(fn ($a) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $a->id)->sum('rate_quote.importo'))->all();
}

it('il base con la voce a € 1.200,00 scritta: dopo la fattura da € 6.100,00 in sforo a rata integrativa l\'integrativo chiede € 4.659,02 (Ugo € 2.209,02, Bice € 2.450,00), e € 240,98 non li chiede nessuno', function () {
    $s = idsScenario($this);
    expect(idsCrea($this, $s, 'Preventivo 2026', '1.200,00'))->toBeNull()
        ->and(idsChiesto($s, 'Preventivo 2026'))->toBe(['ugo' => 30000, 'bice' => 60000]);

    idsFattura($this, $s);
    expect(idsCrea($this, $s, 'Integrativo 2026', '4.900,00'))->toBeNull();

    $integrativo = idsChiesto($s, 'Integrativo 2026');
    expect($integrativo)->toBe(['ugo' => 220902, 'bice' => 245000])
        ->and(array_sum($integrativo))->toBe(465902)
        // I due piani insieme, contro la spesa meno il già versato (€ 5.800,00): mancano € 240,98.
        ->and(580000 - array_sum(idsChiesto($s, 'Preventivo 2026')) - array_sum($integrativo))->toBe(24098);
});

it('il base con l\'importo vuoto: dopo la stessa fattura l\'integrativo si rifiuta, perché il base chiede già la voce intera; ricalcolato, il base chiede € 5.800,00', function () {
    $s = idsScenario($this);
    expect(idsCrea($this, $s, 'Preventivo 2026', ''))->toBeNull()
        ->and(idsChiesto($s, 'Preventivo 2026'))->toBe(['ugo' => 30000, 'bice' => 60000]);

    idsFattura($this, $s);
    expect(idsCrea($this, $s, 'Integrativo 2026', '4.900,00'))->toContain('supera il suo budget (€ 6.100,00)');

    $piano = PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2026')->firstOrFail();
    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]))->assertRedirect();

    expect(idsChiesto($s, 'Preventivo 2026'))->toBe(['ugo' => 275000, 'bice' => 305000]);
});
