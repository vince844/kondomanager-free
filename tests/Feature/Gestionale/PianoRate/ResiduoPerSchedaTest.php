<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il residuo di una rata nelle due schede della pagina del piano. Il credito è netto per la persona: dentro una rata il credito di una persona toglie dal
 * suo debito, anche su un'altra sua unità; il credito di un'altra persona della stessa unità non toglie mai.
 *
 * Il caso: sull'Interno 5 Ugo deve € 50,00 ed Elsa ha € 20,00 a credito, nella stessa rata. La scheda per unità diceva € 30,00 (il gruppo nettato); ora
 * € 50,00. Bice ha l'Interno C (€ 50,00) e il box D (€ 20,00 a credito): nella scheda per persona € 30,00, come prima.
 */

use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Immobile;
use App\Services\CondominioService;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

function b15rsPersona(array $s, string $nome): Anagrafica
{
    return Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "{$s['seq']}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => strtoupper(str_pad($nome, 6, 'X')) . str_pad((string) $s['seq'], 10, '0', STR_PAD_LEFT)]);
}

function b15rsScenario(): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    $piano = PianoRate::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'numero_rate' => 1, 'stato' => 'approvato']);
    $rata = Rata::create(['piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2026-02-01', 'importo_totale' => 6000, 'stato' => 'emessa']);
    $unita = fn (string $nome) => Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "B15RS-{$s['seq']}-{$nome}", 'nome' => $nome, 'interno' => $nome]);
    $cinque = $unita('5');
    $c = $unita('C');
    $d = $unita('D');
    $quota = fn (Anagrafica $a, Immobile $i, int $importo) => RataQuote::create(['rata_id' => $rata->id, 'anagrafica_id' => $a->id, 'immobile_id' => $i->id,
        'importo' => $importo, 'importo_pagato' => 0, 'stato' => $importo < 0 ? 'credito' : 'da_pagare', 'data_scadenza' => '2026-02-01']);
    $ugo = b15rsPersona($s, 'Ugo');
    $elsa = b15rsPersona($s, 'Elsa');
    $bice = b15rsPersona($s, 'Bice');
    $quota($ugo, $cinque, 5000);
    $quota($elsa, $cinque, -2000);
    $quota($bice, $c, 5000);
    $quota($bice, $d, -2000);

    return ['piano' => $piano->fresh(['rate.rateQuote.anagrafica', 'rate.rateQuote.immobile']), 'cinque' => $cinque, 'c' => $c, 'd' => $d, 'ugo' => $ugo, 'elsa' => $elsa, 'bice' => $bice];
}

it('nella scheda per unità il credito di Elsa non toglie dal debito di Ugo: l\'Interno 5 dice € 50,00', function () {
    $s = b15rsScenario();

    $perUnita = app(PianoRateQuoteService::class)->quotePerImmobile($s['piano'])->keyBy(fn ($r) => $r['immobile']['id'] ?? null);

    expect($perUnita[$s['cinque']->id]['rate'][0]['residuo'])->toBe(5000)
        ->and($perUnita[$s['c']->id]['rate'][0]['residuo'])->toBe(5000)
        ->and($perUnita[$s['d']->id]['rate'][0]['residuo'])->toBe(0);
});

it('controprova: nella scheda per persona il credito di Bice toglie dal suo debito, anche su un\'altra sua unità', function () {
    $s = b15rsScenario();

    $perPersona = app(PianoRateQuoteService::class)->quotePerAnagrafica($s['piano'])->keyBy(fn ($r) => $r['anagrafica']['id']);

    expect($perPersona[$s['bice']->id]['rate'][0]['residuo'])->toBe(3000)
        ->and($perPersona[$s['ugo']->id]['rate'][0]['residuo'])->toBe(5000)
        ->and($perPersona[$s['elsa']->id]['rate'][0]['residuo'])->toBe(0);
});
