<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un movimento a giornale tiene la voce finché non è annullato: annullato vuol dire nello stato «annullata», o con una figlia che lo storna
 * (l'elenco esplicito di `TipoMovimentoContabile::storniDiScrittura`, e la rettifica dello storno di un incasso, che ha il padre annullato). Le
 * scritture di annullamento non contano. La compensazione con il credito (`storno_credito`) è una figlia viva dell'incasso, non il suo storno.
 * Il caso: Via Verdi, gli interessi di mora registrati al 29/06/2026 e versati dalla rotta dell'incasso, sulla voce «Interessi di mora».
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\BloccoDellaVoce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Gli interessi di chi è dato, registrati al 29/06/2026: la scenario e la quota degli interessi. */
function b15mvInteressi($test, string $chi, array $opz = []): array
{
    $s = accViaVerdi($test, $opz);
    $test->travelTo('2026-06-29 10:00:00');
    $rataId = accRegistra($test, $s, accProposta($test, $s, [$chi])->json())->assertCreated()->json('rate.0');

    return [$s, RataQuote::where('rata_id', $rataId)->where('anagrafica_id', $s['persone'][$chi]->id)->firstOrFail()];
}

function b15mvElimina($test, array $s)
{
    return $test->actingAs($test->user)->from('/piano-dei-conti')
        ->delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', [$s['c'], $s['e'], $s['interessi']->piano_conto_id, $s['interessi']]))
        ->assertRedirect();
}

function b15mvIncasso(array $s): int
{
    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

test('gli interessi versati con un incasso e poi stornati dalla rotta: nessun movimento tiene la voce, e la voce si elimina', function () {
    [$s, $quota] = b15mvInteressi($this, 'Neri');
    $euro = number_format($quota->importo / 100, 2, '.', '');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-06-29', 'importo_totale' => $euro,
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => $euro]],
    ])->assertSessionHasNoErrors()->assertRedirect();
    $incasso = b15mvIncasso($s);
    expect(DB::table('righe_scritture')->where('scrittura_id', $incasso)->where('voce_spesa_id', $s['interessi']->id)->count())->toBe(1)
        ->and(BloccoDellaVoce::movimentiAGiornale([(int) $s['interessi']->id])['movimenti'])->toBe(1);

    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]), stornoVersamentoTornato())
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('scritture_contabili')->where('id', $incasso)->value('stato'))->toBe('annullata')
        ->and(DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->value('tipo_movimento'))->toBe('rettifica')
        ->and(BloccoDellaVoce::movimentiAGiornale([(int) $s['interessi']->id])['movimenti'])->toBe(0);

    b15mvElimina($this, $s);
    expect(session('message')['type'] ?? null)->not->toBe('error')
        ->and(Conto::find($s['interessi']->id))->toBeNull();
});

test('gli interessi versati con il credito, dalla rotta: l\'incasso con la compensazione figlia è vivo, tiene la voce, e la voce non si elimina', function () {
    // Verdi ha € 500,00 di credito nella rata 0 (il saldo iniziale) e la rata 1 scaduta il 31/03/2026: gli interessi si pagano con il credito.
    [$s, $quota] = b15mvInteressi($this, 'Verdi', ['metodo' => 'rata_zero', 'saldo_verdi' => -50000]);
    $credito = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate.numero_rata', 0)->where('rate_quote.anagrafica_id', $s['persone']['Verdi']->id)->value('rate_quote.id');
    $euro = number_format($quota->importo / 100, 2, '.', '');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Verdi']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-06-29', 'importo_totale' => '0.00',
        'descrizione' => 'Compensazione', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => $euro], ['rata_id' => $credito, 'importo' => '-' . $euro]],
    ])->assertSessionHasNoErrors()->assertRedirect();
    $incasso = b15mvIncasso($s);
    $compensazione = DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'storno_credito')->first();
    expect($compensazione)->not->toBeNull()
        ->and(DB::table('righe_scritture')->whereIn('scrittura_id', [$incasso, $compensazione->id])->where('voce_spesa_id', $s['interessi']->id)->sum('importo'))->toBe($quota->importo)
        ->and(BloccoDellaVoce::movimentiAGiornale([(int) $s['interessi']->id])['movimenti'])->toBe(1);

    b15mvElimina($this, $s);
    expect(session('message'))->toBe(['type' => 'error', 'message' => BloccoDellaVoce::rifiutoDeiMovimentiAGiornale(1)])
        ->and(Conto::find($s['interessi']->id))->not->toBeNull()
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $s['interessi']->id)->count())->toBe(1);

    // Stornato l'incasso con la sua compensazione, la voce torna libera.
    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]), stornoVersamentoTornato())
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(BloccoDellaVoce::movimentiAGiornale([(int) $s['interessi']->id])['movimenti'])->toBe(0);
});
