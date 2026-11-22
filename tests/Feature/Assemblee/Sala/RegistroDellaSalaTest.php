<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Assemblee\GuardieDiCancellazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Il registro della sala (R10): ogni gesto è una riga con **l'ora del gesto** (`avvenuta_il`) e **l'ora di arrivo** (`registrata_il`), diverse quando la
 * sala lavora senza rete o si registra dopo dal foglio della sala («registrato dal foglio della sala»); il «Diario» dell'assemblea le legge da sé; il voto
 * prima della conferma non è una riga. Il condominio di prova con una sala tenuta si toglie tutto.
 *
 * Che cosa non copre: la riga della conferma con i voti nel lotto (`VotazioniDellaSalaTest`); le scelte fra due versioni (`GestiDellaSalaTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('dal foglio della sala, il giorno dopo: l\'ora del gesto è quella scritta a mano, l\'arrivo è adesso, e la riga lo dice', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id, 'dal_foglio' => true],
        '2026-12-14 09:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => SalaDiViaRoma12::seduta($a)->id, 'dal_foglio' => true,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-15 18:42'));
    SalaDiViaRoma12::entrano($this, $s, $a, ['gallo'], '2026-12-15 18:47');

    $righe = ViaRoma12::righe($riga);
    expect($righe->pluck('riepilogo')->all())->toBe([
        'Assemblea ordinaria del 15/12/2026: la prima convocazione del 14/12/2026 è deserta · registrato dal foglio della sala',
        'Assemblea ordinaria del 15/12/2026: aperta in seconda convocazione alle 18:42, presidente Rossi Mario, segretario L\'amministratore · registrato dal foglio della sala',
        'Assemblea ordinaria del 15/12/2026: Gallo Enzo entra in sala alle 18:47 · registrato dal foglio della sala',
    ])
        ->and($righe->last()->avvenuta_il->format('Y-m-d H:i'))->toBe('2026-12-15 17:47')
        ->and($righe->last()->registrata_il->format('Y-m-d H:i'))->toBe('2026-12-16 09:00')
        ->and(SalaDiViaRoma12::seduta($a)->dal_foglio)->toBeTrue();
});

it('il «Diario» dell\'assemblea legge le righe della sala; il voto prima della conferma non è una riga', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi']);
    $riga = ViaRoma12::ultimaRiga();
    // La convocazione della prova scrive i convocati a mano, fuori da un gesto: da qui in poi nessuna scrittura della sala resta fuori.
    ContestoDelRegistro::svuotaScrittureFuoriGesto();
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    expect(ViaRoma12::righe($riga))->toHaveCount(0)
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);

    $frasi = collect(DiarioDellAssemblea::righe($a->refresh())['righe'])->pluck('frase')->implode(' | ');
    // Le righe dell'assemblea senza il suo nome davanti, con la maiuscola: «Aperta in seconda convocazione …».
    expect($frasi)->toContain('Rossi Mario entra in sala alle 18:45')->toContain('Aperta in seconda convocazione alle 18:42')
        ->not->toContain('Assemblea ordinaria del 15/12/2026');
});

it('il condominio di prova con una sala tenuta si toglie tutto, dalle foglie alla radice', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('dichiarazione', ['seduta_id' => $seduta->id, 'nome' => 'Rossi Mario', 'testo' => 'A verbale.'],
        '2026-12-15 19:05'));

    RegistroModifiche::durante(Gesto::su('condominio', 'condominio', $s->c->id, 'Condominio di prova'),
        fn () => GuardieDiCancellazione::togliTuttoDelCondominioDiProva((int) $s->c->id));
    foreach (['assemblee', 'assemblee_sedute', 'assemblee_presenze', 'assemblee_votazioni', 'assemblee_voti', 'assemblee_dichiarazioni', 'assemblee_gesti',
        'assemblee_deleghe'] as $tabella) {
        expect(\Illuminate\Support\Facades\DB::table($tabella)->count())->toBe(0, $tabella);
    }
});
