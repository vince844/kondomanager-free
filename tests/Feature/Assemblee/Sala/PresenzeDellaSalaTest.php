<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PresenzaInSala;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Le presenze (R6): entrata e uscita con l'ora, chi esce e rientra ha due righe; «In collegamento» senza conferma se l'assemblea è in videoconferenza o
 * mista, altrimenti con il motivo obbligatorio («Nel motivo niente dati sulla salute»); il collegamento caduto è un'uscita, il ritorno una riga nuova. La
 * barra della costituzione conta le teste presenti: una testa di due persone con una sola di loro.
 *
 * Che cosa non copre: le due versioni di una presenza (`GestiDellaSalaTest`); chi è presente per delega (`DelegheDellAssembleaTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('entrata e uscita con l\'ora; chi esce e rientra ha due righe; la barra conta le teste presenti', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $persona = ['anagrafica_id' => $s->persone['anna']->id];

    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', ...SalaDiViaRoma12::altri(7)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $persona, 'come' => 'in_sala'], '2026-12-15 18:50', 0));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => $persona], '2026-12-15 19:20', 1));
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $persona, 'come' => 'in_sala'], '2026-12-15 19:40', 2));

    $righe = PresenzaInSala::query()->where('seduta_id', $seduta->id)->where('anagrafica_id', $s->persone['anna']->id)->orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and($righe->map(fn ($p) => [$p->entrata_il->format('H:i'), $p->uscita_il?->format('H:i')])->all())->toBe([['17:50', '18:20'], ['18:40', null]])
        ->and($r['versione'])->toBe(3);

    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['barra']['barra'])->toBe('9 su 26 · 329,25 su 1.000,00')
        ->and($sala['barra']['costituita'])->toBeFalse()
        ->and($sala['barra']['mancano'])->toBe('mancano 4,09 millesimi');
});

it('«In collegamento» in un\'assemblea in presenza vuole il motivo, e il registro lo scrive nel perché; con la videoconferenza nell\'avviso no', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $gesto = fn (array $piu = []) => SalaDiViaRoma12::gesto('entra', $piu + ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['gallo']->id],
        'come' => 'in_collegamento'], '2026-12-15 18:50');

    $r = SalaDiViaRoma12::manda($this, $s, $a, [$gesto()])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Scrivi perché è in collegamento: l\'avviso non prevede la videoconferenza. Nel motivo niente dati sulla salute.');

    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, $gesto(['motivo' => 'È fuori città per lavoro']));
    $ultima = ViaRoma12::righe($riga)->last();
    expect($ultima->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026: Gallo Enzo in collegamento alle 18:50')
        ->and($ultima->perche)->toBe('È fuori città per lavoro')
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->value('motivo_collegamento'))->toBe('È fuori città per lavoro');

    // Con la videoconferenza nell'avviso: senza conferma. Un'altra assemblea dello stesso condominio, mista, con il consenso registrato.
    $a2 = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, null, ['modalita' => 'mista', 'piattaforma' => 'Jitsi',
        'consenso_videoconferenza_il' => '2026-10-01', 'consenso_videoconferenza_modo' => 'Delibera del 01/10/2026']), $s);
    $seduta2 = SalaDiViaRoma12::apriLaSeconda($this, $s, $a2);
    $r = SalaDiViaRoma12::manda($this, $s, $a2, [SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta2->id,
        'persona' => ['anagrafica_id' => $s->persone['gallo']->id], 'come' => 'in_collegamento'], '2026-12-15 18:50')])->json('risposte.0');
    expect($r['esito'])->toBe('applicato');
});

it('il collegamento caduto è un\'uscita, il ritorno una riga nuova', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, null, ['modalita' => 'videoconferenza', 'piattaforma' => 'Jitsi',
        'consenso_videoconferenza_il' => '2026-10-01', 'consenso_videoconferenza_modo' => 'Delibera del 01/10/2026']), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $persona = ['anagrafica_id' => $s->persone['gallo']->id];
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $persona, 'come' => 'in_collegamento'], '2026-12-15 18:50'));
    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => $persona, 'caduto' => true], '2026-12-15 19:05'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $persona, 'come' => 'in_collegamento'], '2026-12-15 19:08'));

    $righe = PresenzaInSala::query()->where('seduta_id', $seduta->id)->orderBy('id')->get();
    expect($righe->map(fn ($p) => [$p->come->value, $p->caduto, $p->uscita_il?->format('H:i')])->all())
        ->toBe([['in_collegamento', true, '18:05'], ['in_collegamento', false, null]])
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())->toBe([
            'Assemblea ordinaria del 15/12/2026: Gallo Enzo, il collegamento è caduto alle 19:05',
            'Assemblea ordinaria del 15/12/2026: Gallo Enzo in collegamento alle 19:08',
        ]);
});

it('una testa di due comproprietari è presente con uno solo, che la rappresenta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['marco']);

    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['barra']['barra'])->toBe('1 su 26 · 70,00 su 1.000,00')
        ->and(collect($sala['teste'])->firstWhere('nome', 'Russo Anna e Russo Marco')['presente'])->toBe('Russo Marco');
});

it('chi non è di questa assemblea non entra', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $estraneo = $s->persona('estraneo');

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id,
        'persona' => ['anagrafica_id' => $estraneo->id], 'come' => 'in_sala'])])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Questa persona non è fra i condòmini di questa assemblea, né un delegato con una delega valida.')
        ->and(PresenzaInSala::query()->count())->toBe(0);
});
