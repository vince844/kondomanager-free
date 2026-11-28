<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il «Credito disponibile» dell'estratto conto è il netto della persona, e il rimborso è solo delle rate emesse.
 *
 * Bice Neri ha l'Interno A, € 500,00 a rata (il caso del progetto ha € 40,00: cambia la cifra, non la forma), e il Box D, con un saldo iniziale a credito di € 120,00 ripartito sulle 12 rate: −€ 10,00 a rata.
 * Sono emesse le rate 1 e 2. Il riquadro diceva «Credito disponibile € 120,00» accanto a un saldo da versare: contava le quote a credito anche
 * delle rate in bozza, e non toglieva il debito delle altre quote. Il rimborso offriva 12 quote, 10 di rate in bozza.
 *
 * La regola: il riquadro dice il netto della persona sulle rate emesse (€ 1.000,00 di debito, € 20,00 di credito: € 980,00 da versare), con il
 * credito lordo sotto in piccolo (€ 120,00). Il rimborso a chi ha debiti aperti chiede una conferma nella pagina (la prova è nel vitest della
 * pagina, `EstrattoContoCreditoNetto.test.ts`).
 *
 * Lo scenario è quello di `Beta59EstrattoScenari.php`, con la persona chiamata Bice Neri.
 */

use App\Models\Immobile;
use App\Models\Gestionale\RataQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59EstrattoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

/** Bice con l'Interno A (€ 500,00 a rata) e il Box D (saldo a credito di € 120,00 su tutte le rate), le rate 1 e 2 emesse. */
function cdnScenario($test): array
{
    // `b59eScenario` scrive il piano subito: qui il Box D e il suo saldo devono esserci prima, perché il piano li assorba.
    $s = b59eScenario($test);
    DB::table('anagrafiche')->where('id', $s['mario']->id)->update(['nome' => 'Bice Neri']);
    $s['mario'] = $s['mario']->fresh();
    $box = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'box', 'codice_immobile' => "CDN-{$s['seq']}-D", 'nome' => 'Box D', 'interno' => 'D']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['mario']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $s['box'] = $box;
    $test->actingAs($s['admin'])->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['mario']->id, 'immobile_id' => $box->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => -12000,
    ])->assertSessionHasNoErrors();
    // Un secondo piano, scritto dopo il saldo, che lo assorbe su tutte le rate; il primo si toglie.
    $test->actingAs($s['admin'])->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])->assertSessionHasNoErrors();
    $test->actingAs($s['admin'])->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $s['piano']]))->assertRedirect();
    expect(DB::table('piani_rate')->where('id', $s['piano']->id)->whereNull('deleted_at')->exists())->toBeFalse();
    $test->actingAs($s['admin'])->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026 bis', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();
    $s['piano'] = \App\Models\Gestionale\PianoRate::where('nome', 'Preventivo 2026 bis')->where('condominio_id', $s['c']->id)->firstOrFail();
    $test->actingAs($s['admin'])->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])
        ->assertSessionHasNoErrors();
    b59eEmetti($s, [1, 2], '2026-01-05');

    // Le cifre della prova: € 500,00 sull'Interno A e −€ 10,00 sul Box D in ognuna delle 12 rate.
    $quote = RataQuote::whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', '>', 0)->pluck('id'))->get();
    expect($quote->where('immobile_id', $s['unita']->id)->pluck('importo')->unique()->values()->all())->toBe([50000])
        ->and($quote->where('immobile_id', $box->id)->pluck('importo')->unique()->values()->all())->toBe([-1000])
        ->and($quote->where('immobile_id', $box->id)->count())->toBe(12);

    return $s;
}

function cdnPagina($test, array $s): array
{
    return $test->actingAs($s['admin'])->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['mario']]))->assertOk()->viewData('page')['props'];
}

it('il riquadro dice € 980,00 da versare, non «Credito disponibile € 120,00», e sotto il credito lordo di € 120,00', function () {
    $s = cdnScenario($this);

    $stats = cdnPagina($this, $s)['stats'];

    expect($stats['credito_disponibile_raw'])->toBe(0)
        ->and($stats['credito_da_versare_raw'])->toBe(98000)
        ->and($stats['credito_da_versare'])->toBe('€ 980,00')
        ->and($stats['credito_lordo_raw'])->toBe(12000)
        ->and($stats['credito_lordo'])->toBe('€ 120,00');
});

it('controprova — con il debito delle rate emesse pagato, il riquadro dice il credito netto delle rate emesse e niente da versare', function () {
    $s = cdnScenario($this);
    b59eIncassa($s, 1, '2026-01-10', '500.00');
    b59eIncassa($s, 2, '2026-02-10', '500.00');

    $stats = cdnPagina($this, $s)['stats'];

    expect($stats['credito_disponibile_raw'])->toBe(2000)
        ->and($stats['credito_da_versare_raw'])->toBe(0)
        ->and($stats['credito_lordo_raw'])->toBe(12000);
});
