<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli addebiti a una persona sola nelle cifre del suo estratto conto. La card «Addebiti personali» somma la lista degli addebiti della persona
 * (`addebiti`), e dice che sono già «nel totale addebiti»; la card «Totale addebiti» dice «Rate emesse e addebiti». Qui si prova che è vero: un
 * contributo e una spesa per il ritardo senza fattura alzano «Totale addebiti» e il saldo finale della loro cifra, la lista li somma alla stessa
 * cifra, e l'annullamento li toglie dalle due.
 *
 * Il caso è Via Verdi con le voci degli accessori (`AccessoriScenario.php`): la prima rata emessa il 15/01, Neri su U1.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

it('un contributo e una spesa per il ritardo senza fattura sono dentro «Totale addebiti» e il saldo, con la cifra della lista; annullati ne escono', function () {
    $s = accViaVerdi($this);
    $neri = $s['persone']['Neri'];
    // Le cifre della pagina: «€ 1.234,56» in centesimi, il saldo in centesimi, la lista degli addebiti.
    $leggi = function () use ($s, $neri) {
        $props = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $neri]))->assertOk()->viewData('page')['props'];

        return [
            'totale' => (int) preg_replace('/[^0-9-]/', '', $props['stats']['totale_addebiti']),
            'saldo' => (int) $props['stats']['saldo_raw'],
            'lista' => array_sum(array_column($props['addebiti'], 'importo')),
            'quanti' => count($props['addebiti']),
        ];
    };

    $prima = $leggi();
    expect([$prima['lista'], $prima['quanti']])->toBe([0, 0]);

    $contributo = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Cantina, U1', 'scadenza' => '2026-02-15']])
        ->assertCreated()->json('rate.0');
    $spesa = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Raccomandata', 'natura' => 'accessorio',
        'tipo_accessorio' => 'sollecito', 'base' => 'regolamento', 'riferimento' => 'art. 20', 'conto_id' => $s['postali']->id, 'scadenza' => '2026-02-15']])
        ->assertCreated()->json('rate.0');

    $dopo = $leggi();
    expect([$dopo['lista'], $dopo['quanti']])->toBe([7500, 2])
        ->and($dopo['totale'] - $prima['totale'])->toBe(7500)
        ->and($dopo['saldo'] - $prima['saldo'])->toBe(7500);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $contributo]))->assertOk();
    $tolto = $leggi();
    expect([$tolto['lista'], $tolto['quanti']])->toBe([2500, 1])
        ->and($tolto['totale'] - $prima['totale'])->toBe(2500)
        ->and($tolto['saldo'] - $prima['saldo'])->toBe(2500);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $spesa]))->assertOk();
    expect($leggi())->toBe($prima);
});

it('la stampa dice sotto «Totale addebiti» quello che dice la pagina: «Rate emesse e addebiti»', function () {
    $s = accViaVerdi($this);
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Cantina, U1', 'scadenza' => '2026-02-15']])->assertCreated();
    $dati = null;
    View::composer('pdf.gestionale.estratto_conto_anagrafica', function ($v) use (&$dati) {
        $dati ??= $v->getData();
    });
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['Neri']]));
    $r->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf');
    $testo = preg_replace('/\s+/u', ' ', strip_tags(view('pdf.gestionale.estratto_conto_anagrafica', $dati)->render()));

    expect($testo)->toContain('Totale addebiti ' . $dati['stats']['totale_addebiti'] . ' Rate emesse e addebiti');
});
