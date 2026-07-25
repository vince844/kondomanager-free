<?php

/**
 * GenerateRateQuotesActionTest
 *
 * Copre la gestione dei saldi iniziali misti (debiti positivi e crediti
 * negativi) in App\Actions\PianoRate\GenerateRateQuotesAction, che prima di
 * questo file non aveva alcun test dedicato (solo test indiretti su
 * SaldoEsercizioService e CreditoService, servizi diversi).
 *
 * L'azione viene invocata direttamente con array di totali/saldi costruiti a
 * mano: non serve passare da CalcoloQuoteService/GenerateSaldiAction per
 * isolare la logica di distribuzione sulle rate.
 */

use App\Actions\PianoRate\GenerateRateQuotesAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creaPianoRatePerQuoteTest(array $overrides = []): PianoRate
{
    $condominio = Condominio::factory()->create();
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);

    return PianoRate::create(array_merge([
        'gestione_id'          => $gestione->id,
        'condominio_id'        => $condominio->id,
        'nome'                 => 'Piano Rate Test',
        'numero_rate'          => 1,
        'metodo_distribuzione' => 'tutte_rate',
        'stato'                => 'bozza',
        'tipo'                 => 'ordinario',
    ], $overrides));
}

it('genera la Rata 0 con condomini misti a debito e a credito', function () {
    $pianoRate = creaPianoRatePerQuoteTest([
        'metodo_distribuzione' => 'rata_zero',
        'numero_rate'          => 1,
    ]);

    $anagraficaDebito  = Anagrafica::factory()->create();
    $anagraficaCredito = Anagrafica::factory()->create();

    $saldi = [
        $anagraficaDebito->id => [
            0 => ['importo' => 50000, 'meta_storico' => ['tipo_riparto' => 'nominale']],
        ],
        $anagraficaCredito->id => [
            0 => ['importo' => -20000, 'meta_storico' => ['tipo_riparto' => 'nominale']],
        ],
    ];

    $stats = app(GenerateRateQuotesAction::class)->execute(
        $pianoRate,
        [],
        ['2026-01-05'],
        $saldi
    );

    $rataZero = Rata::where('piano_rate_id', $pianoRate->id)->where('numero_rata', 0)->first();

    expect($rataZero)->not->toBeNull()
        ->and($rataZero->importo_totale)->toBe(30000); // 50.000 - 20.000

    $quoteDebito = RataQuote::where('rata_id', $rataZero->id)
        ->where('anagrafica_id', $anagraficaDebito->id)->first();
    $quoteCredito = RataQuote::where('rata_id', $rataZero->id)
        ->where('anagrafica_id', $anagraficaCredito->id)->first();

    expect($quoteDebito->importo)->toBe(50000)
        ->and($quoteDebito->stato)->toBe('da_pagare')
        ->and($quoteDebito->tipo)->toBe('saldo_iniziale');

    expect($quoteCredito->importo)->toBe(-20000)
        ->and($quoteCredito->stato)->toBe('credito')
        ->and($quoteCredito->tipo)->toBe('saldo_iniziale');

    expect($stats['quote_create'])->toBe(2);
});

it('non applica clamping quando il credito spalmato (tutte_rate) supera la quota ordinaria della singola rata', function () {
    $pianoRate = creaPianoRatePerQuoteTest([
        'metodo_distribuzione' => 'tutte_rate',
        'numero_rate'          => 2,
    ]);

    $anagrafica = Anagrafica::factory()->create();

    // 100€ di quota ordinaria totale => 50€/rata
    $totaliPerImmobile = [$anagrafica->id => [0 => 10000]];

    // 200€ di credito spalmato su 2 rate => 100€/rata, supera la quota ordinaria di ogni rata
    $saldi = [
        $anagrafica->id => [
            0 => ['importo' => -20000, 'meta_storico' => ['tipo_riparto' => 'nominale']],
        ],
    ];

    app(GenerateRateQuotesAction::class)->execute(
        $pianoRate,
        $totaliPerImmobile,
        ['2026-01-05', '2026-02-05'],
        $saldi
    );

    $quote = RataQuote::whereHas('rata', fn ($q) => $q->where('piano_rate_id', $pianoRate->id))
        ->where('anagrafica_id', $anagrafica->id)
        ->get();

    expect($quote)->toHaveCount(2);

    // Ogni rata: 5.000 (quota ordinaria) - 10.000 (quota saldo) = -5.000, nessun clamping a zero
    foreach ($quote as $q) {
        expect($q->importo)->toBe(-5000)
            ->and($q->stato)->toBe('credito');
    }
});

describe('riga con importo finale a zero', function () {

    it('non viene scartata se quota ordinaria e quota saldo si compensano esattamente', function () {
        $pianoRate = creaPianoRatePerQuoteTest([
            'metodo_distribuzione' => 'prima_rata',
            'numero_rate'          => 1,
        ]);

        $anagrafica = Anagrafica::factory()->create();

        $totaliPerImmobile = [$anagrafica->id => [0 => 5000]]; // 50€ di quota ordinaria
        $saldi = [
            $anagrafica->id => [
                0 => ['importo' => -5000, 'meta_storico' => ['tipo_riparto' => 'nominale']], // credito esatto di 50€
            ],
        ];

        app(GenerateRateQuotesAction::class)->execute(
            $pianoRate,
            $totaliPerImmobile,
            ['2026-01-05'],
            $saldi
        );

        $quote = RataQuote::whereHas('rata', fn ($q) => $q->where('piano_rate_id', $pianoRate->id))
            ->where('anagrafica_id', $anagrafica->id)
            ->get();

        expect($quote)->toHaveCount(1);
        expect($quote->first()->importo)->toBe(0)
            ->and($quote->first()->stato)->toBe('credito');
    });

    /**
     * REGRESSIONE (aggiornato in beta.27, revisione avversariale): un soggetto
     * presente nel calcolo con quota realmente zero non viene più scartato senza
     * traccia. Prima di questa correzione un'unità interamente coperta dal
     * "già versato" (beta.26) spariva dalle rate generate: guardandole non c'era
     * modo di distinguere "non doveva nulla" da "esclusa per errore". Ora riceve
     * una riga documentaria a importo zero, una sola volta (sulla prima rata).
     */
    it('un soggetto a quota realmente zero riceve una riga documentaria, non viene scartato', function () {
        $pianoRate = creaPianoRatePerQuoteTest([
            'metodo_distribuzione' => 'prima_rata',
            'numero_rate'          => 1,
        ]);

        $anagrafica = Anagrafica::factory()->create();

        $totaliPerImmobile = [$anagrafica->id => [0 => 0]];

        app(GenerateRateQuotesAction::class)->execute(
            $pianoRate,
            $totaliPerImmobile,
            ['2026-01-05'],
            []
        );

        $quote = RataQuote::whereHas('rata', fn ($q) => $q->where('piano_rate_id', $pianoRate->id))
            ->where('anagrafica_id', $anagrafica->id)
            ->get();

        expect($quote)->toHaveCount(1);
        expect((int) $quote->first()->importo)->toBe(0);
        expect($quote->first()->tipo)->toBe('coperta_da_versamento');
    });

    it('un soggetto del tutto ASSENTE dal calcolo (non in totaliPerImmobile né in saldi) non genera alcuna riga', function () {
        $pianoRate = creaPianoRatePerQuoteTest([
            'metodo_distribuzione' => 'prima_rata',
            'numero_rate'          => 1,
        ]);

        // Nessun soggetto affatto: array completamente vuoto, non una chiave a
        // valore zero. Deve continuare a non generare nulla — questo distingue
        // "il calcolo non conosce questa unità" da "il calcolo la conosce e le
        // deve zero", che sono due situazioni diverse.
        app(GenerateRateQuotesAction::class)->execute(
            $pianoRate,
            [],
            ['2026-01-05'],
            []
        );

        expect(RataQuote::whereHas('rata', fn ($q) => $q->where('piano_rate_id', $pianoRate->id))->count())
            ->toBe(0);
    });

});
