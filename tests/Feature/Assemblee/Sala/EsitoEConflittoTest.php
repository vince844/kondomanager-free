<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * L'esito confermato contro il conto, e il conflitto dopo la conferma:
 *
 * - «Approvata con modifiche» senza motivo solo quando la proposta è «Approvata» e il conflitto non è determinante: con «Respinta», o con un conflitto
 *   determinante, il motivo serve per qualunque esito diverso dalla proposta;
 * - il segno «In conflitto» su un voto già confermato (un gesto in coda da un altro dispositivo) si rifiuta senza il perché; con il perché vale come una
 *   correzione: una riga del registro, e il voto torna da confermare.
 *
 * Gli intervenuti del punto 1 sono undici, 447,00 millesimi: Rossi (65,00), Russo Marco (70,00), Colombo Rita (90,00) e otto condòmini da 27,75.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il punto 1 votato alle 19:00 dagli undici in sala. */
function eecVoto($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $p1 = $a->punti()->where('numero', 1)->value('id');
    $v = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1], '2026-12-15 19:00'));

    return [$s, $a, VotazioneDelPunto::query()->findOrFail($v['votazione_id'])];
}

function eecConferma($t, $s, $a, VotazioneDelPunto $v, array $piu, string $alle): array
{
    return SalaDiViaRoma12::manda($t, $s, $a, [SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v->id] + $piu, $alle)])->json('risposte.0');
}

it('«Approvata con modifiche» contro una proposta «Respinta» chiede il motivo, come ogni altro esito diverso', function () {
    [$s, $a, $v] = eecVoto($this);
    foreach (['rossi', 'rita', 'c30', 'c31', 'c32', 'c33', 'c34'] as $chi) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v->id, 'testa' => 'p-' . $s->persone[$chi]->id, 'voto' => 'contrario'],
            '2026-12-15 19:01', 0));
    }
    expect($v->refresh()->esito_proposto?->value)->toBe('respinta');

    $r = eecConferma($this, $s, $a, $v, ['esito' => 'approvata_con_modifiche'], '2026-12-15 19:03');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('L\'esito proposto è «Respinta»: per confermare «Approvata con modifiche» scrivi il motivo.');
    $r = eecConferma($this, $s, $a, $v, ['esito' => 'approvata_con_modifiche', 'motivo' => 'Il presidente accoglie la proposta di Rossi'], '2026-12-15 19:04');
    expect($r['esito'])->toBe('applicato')->and($v->refresh()->motivo)->toBe('Il presidente accoglie la proposta di Rossi');
});

it('con un conflitto determinante il motivo serve anche per «Approvata con modifiche»; senza conflitto no', function () {
    [$s, $a, $v] = eecVoto($this);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v->id], '2026-12-15 19:01'));
    foreach (['rita', 'rossi'] as $chi) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conflitto', ['votazione_id' => $v->id, 'testa' => 'p-' . $s->persone[$chi]->id,
            'in_conflitto' => true], '2026-12-15 19:02'));
    }
    expect($v->refresh()->esito_proposto?->value)->toBe('approvata')->and($v->conflitto_determinante)->toBeTrue();

    $r = eecConferma($this, $s, $a, $v, ['esito' => 'approvata_con_modifiche'], '2026-12-15 19:03');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Il voto in conflitto è determinante: per confermare «Approvata con modifiche» scrivi il motivo.');

    // Tolto il conflitto di Rossi, il conflitto non è più determinante: contro «Approvata», «Approvata con modifiche» resta senza motivo.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conflitto', ['votazione_id' => $v->id, 'testa' => 'p-' . $s->persone['rossi']->id,
        'in_conflitto' => false], '2026-12-15 19:04'));
    expect($v->refresh()->conflitto_determinante)->toBeFalse()
        ->and(eecConferma($this, $s, $a, $v, ['esito' => 'approvata_con_modifiche'], '2026-12-15 19:05')['esito'])->toBe('applicato');
});

it('«In conflitto» su un voto già confermato si rifiuta senza il perché; con il perché è una correzione: la riga, e il voto torna da confermare', function () {
    [$s, $a, $v] = eecVoto($this);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v->id], '2026-12-15 19:01'));
    expect(eecConferma($this, $s, $a, $v, ['esito' => 'approvata'], '2026-12-15 19:02')['esito'])->toBe('applicato');
    $testa = 'p-' . $s->persone['rita']->id;

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('conflitto', ['votazione_id' => $v->id, 'testa' => $testa, 'in_conflitto' => true],
        '2026-12-15 19:03')], 'telefono di Marco')->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Il voto del punto 1 è confermato: per segnare il conflitto d\'interessi scrivi il perché.')
        ->and($v->refresh()->voti()->where('testa', $testa)->value('in_conflitto'))->toBeFalsy();

    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conflitto', ['votazione_id' => $v->id, 'testa' => $testa, 'in_conflitto' => true,
        'nota' => 'Titolare dell\'impresa', 'perche' => 'Lo dice dopo la conferma'], '2026-12-15 19:04'));
    expect($v->refresh()->confermata_il)->toBeNull()
        ->and((bool) $v->voti()->where('testa', $testa)->value('in_conflitto'))->toBeTrue()
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())->toBe(['Assemblea ordinaria del 15/12/2026: conflitto d\'interessi di Colombo Rita sul punto 1 '
            . 'segnato dopo la conferma; il voto è da confermare di nuovo'])
        ->and(ViaRoma12::righe($riga)->first()->perche)->toBe('Lo dice dopo la conferma');
});
