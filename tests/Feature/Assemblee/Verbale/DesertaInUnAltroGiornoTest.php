<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * «Segna deserta» la prima convocazione segnata un altro giorno (decisione 604 (5)): la prima era fissata lunedì 14/12 alle 8:00 e l'amministratore la segna
 * martedì 15/12 alle 18:40. L'ora della deserta è quella scritta nella finestra (`dichiarata_il`, fra l'ora della convocazione e adesso); senza, il giorno
 * e l'ora della prima convocazione, non l'ora del gesto. La riga del registro e l'annotazione «mancata costituzione» la dicono.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-15 18:40');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function digAssemblea($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);

    return [$s, SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s)];
}

it('senza l\'ora scritta, la prima segnata il giorno dopo è deserta al giorno e all\'ora della convocazione', function () {
    [$s, $a] = digAssemblea($this);
    $prima = SalaDiViaRoma12::seduta($a, 'prima');
    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id], '2026-12-15 18:40'));
    expect($prima->refresh()->chiusa_il->copy()->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-12-14 08:00')
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())
        ->toBe(['Assemblea ordinaria del 15/12/2026: la prima convocazione del 14/12/2026 è deserta (segnata alle 08:00 del 14/12/2026)']);
});

it('con l\'ora scritta nella finestra vale quella; fuori dall\'intervallo fra la convocazione e adesso si rifiuta', function () {
    [$s, $a] = digAssemblea($this);
    $prima = SalaDiViaRoma12::seduta($a, 'prima');
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id, 'dichiarata_il' => '2026-12-14 07:50'],
        '2026-12-15 18:40')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('L\'ora in cui la seduta è deserta è fra l\'ora della convocazione e adesso.');
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id, 'dichiarata_il' => 'ieri mattina'],
        '2026-12-15 18:40')])->json('risposte.0');
    expect($r['messaggio'])->toBe('L\'ora in cui la seduta è deserta non è nella forma giusta.');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id, 'dichiarata_il' => '2026-12-14 08:40'], '2026-12-15 18:40'));
    expect($prima->refresh()->chiusa_il->copy()->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-12-14 08:40');

    $dati = null;
    View::composer('pdf.gestionale.assemblea_registro_verbali', function ($v) use (&$dati) {
        $dati = $v->getData();
    });
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro.annotazione', [$s->c, $prima->id]))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($dati['annotazione']['frasi'][0])->toBe('Il giorno 14 dicembre 2026, alle ore 08:00, in prima convocazione, l\'assemblea ordinaria dei condòmini del '
        . 'Condominio Via Roma 12 non si è costituita (dichiarata deserta alle ore 08:40).');
});
