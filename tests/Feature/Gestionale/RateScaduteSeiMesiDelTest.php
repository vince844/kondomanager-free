<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il filtro `sei_mesi_del` dell'elenco «Rate scadute», che apre «Tutte le rate di questo esercizio →» del riquadro dei sei mesi: tiene le **persone** con
 * almeno una rata di quella data (come `sei_mesi_entro`) e non toglie rate a nessuno. La riga dell'elenco resta «tutto lo scaduto» con le sue fasce; le rate
 * di quella data hanno un segno, e sotto la cifra della riga c'è «di cui». La lettera fatta da «tutte le N dei filtri» è la stessa delle persone spuntate.
 */

use App\Services\Gestionale\Debiti\ElencoRateScadute;
use App\Services\Gestionale\Lettere\EmissioneLettere;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function rsdPagina($test, array $s, array $q): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $q))->assertOk()->viewData('page')['props'];
}

it('il filtro tiene le persone con rate di quella data: la riga ha la stessa cifra e le stesse fasce di senza, con il «di cui» e il segno sulle rate', function () {
    $s = smaViaVerdi($this);
    // Rossi paga le rate del 2025: non ha più rate del 30/06/2026, e il filtro non lo tiene.
    lcdIncassa($this, $s, 'rossi', [lcdQuota($s, 'rossi', 1) => 10000, lcdQuota($s, 'rossi', 2) => 10000], '2026-05-20');
    $senza = collect(rsdPagina($this, $s, [])['righe']['data'])->keyBy('nome');
    $con = rsdPagina($this, $s, ['sei_mesi_del' => '2026-06-30']);
    $righe = collect($con['righe']['data'])->keyBy('nome');

    expect($righe->keys()->sort()->values()->all())->toBe(['Bianchi', 'Neri', 'Verdi'])
        ->and($con['filtri']['sei_mesi_del'])->toBe('2026-06-30')
        ->and($righe['Bianchi']['importo'])->toBe($senza['Bianchi']['importo'])->and($righe['Bianchi']['importo'])->toBe(50000)
        ->and($righe['Bianchi']['fasce'])->toBe($senza['Bianchi']['fasce'])
        ->and($righe['Bianchi']['di_cui_sei_mesi'])->toBe(20000)
        ->and(collect($righe['Bianchi']['rate'])->where('del_sei_mesi', true)->count())->toBe(2)
        ->and(collect($righe['Bianchi']['rate'])->count())->toBe(5)
        ->and($con['sei_mesi_del'])->toBe(['data' => '2026-06-30', 'esercizio' => 'Esercizio 2025', 'persone' => 3, 'cifra' => 60000]);
});

it('la somma dei «di cui» è la cifra della riga del riquadro', function () {
    $s = smaViaVerdi($this);
    $con = rsdPagina($this, $s, ['sei_mesi_del' => '2026-06-30']);

    expect(collect($con['righe']['data'])->sum('di_cui_sei_mesi'))->toBe(smaRiga(smaRiquadro($s), 'Esercizio 2025')['cifra'])
        ->and(collect(rsdPagina($this, $s, ['sei_mesi_del' => '2027-06-30'])['righe']['data'])->sum('di_cui_sei_mesi'))->toBe(smaRiga(smaRiquadro($s), 'Esercizio 2026')['cifra']);
});

it('un valore che non è una data torna al valore di serie, senza errore', function () {
    $s = smaViaVerdi($this);
    $f = app(ElencoRateScadute::class)->filtriNormalizzati(['sei_mesi_del' => '30/06/2026']);

    expect($f['sei_mesi_del'])->toBeNull()
        ->and(collect(rsdPagina($this, $s, ['sei_mesi_del' => 'domani'])['righe']['data']))->toHaveCount(4);
});

it('la lettera da «tutte le N dei filtri» con il filtro è uguale a quella delle stesse persone spuntate: chiede tutto lo scaduto', function () {
    $s = smaViaVerdi($this);
    lcdIncassa($this, $s, 'rossi', [lcdQuota($s, 'rossi', 1) => 10000, lcdQuota($s, 'rossi', 2) => 10000], '2026-05-20');
    $lettere = app(EmissioneLettere::class);
    $tutte = $lettere->anteprima($s['c'], ['tipo' => 'diffida', 'tutte' => 1, 'filtri' => ['sei_mesi_del' => '2026-06-30']]);
    $spuntate = $lettere->anteprima($s['c'], ['tipo' => 'diffida', 'persone' => [$s['persone']['bianchi']->id, $s['persone']['neri']->id, $s['persone']['verdi']->id]]);
    $forma = fn ($a) => collect($a['persone'])->map(fn ($p) => [$p['nome'], $p['totale'], $p['righe']])->sortBy(0)->values()->all();

    expect($forma($tutte))->toBe($forma($spuntate))
        ->and(collect($tutte['persone'])->firstWhere('nome', 'Bianchi')['totale'])->toBe(50000);
});
