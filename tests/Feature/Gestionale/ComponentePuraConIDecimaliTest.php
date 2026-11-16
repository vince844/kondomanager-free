<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La copertura del bilancio sommata nel database (`PianoRateQuoteService::totalePuroGeneratoCents`): una componente pura scritta con i decimali si tronca
 * quota per quota, come faceva `(int)` su ogni quota, non sulla somma. Il motore scrive interi: la forma è difensiva.
 */

use App\Services\PianoRateQuoteService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/LetturaDebitiProva.php';
require_once __DIR__.'/SeiMesiPerAgireScenario.php';

it('la componente pura con i decimali si tronca quota per quota, non sulla somma', function () {
    $this->user = smaUtente();
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 400], 'B' => ['bianchi', 300], 'C' => ['verdi', 200], 'D' => ['neri', 100]], 12, 'prima_rata', [], 1200000);
    lcdEmetti($this, $s, [1, 2], '2026-01-02');
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->pluck('rate_quote.id');
    DB::table('rate_quote')->whereIn('id', $quote)->update(['regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 12.6]])]);

    // Ogni quota vale 12 centesimi: troncata la somma, 12,6 × N darebbe di più.
    expect($quote->count())->toBeGreaterThan(1)
        ->and(app(PianoRateQuoteService::class)->totalePuroGeneratoCents($s['piano']->fresh()))->toBe(12 * $quote->count());
});
