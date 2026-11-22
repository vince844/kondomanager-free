<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\PresenzaInSala;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * «Segna deserta» anche per la seconda convocazione aperta, con la regola della prima: finché non si vota (né si tratta) un punto. L'assemblea diventa
 * deserta (art. 1136 c. 3 c.c.), chi è in sala esce all'ora del gesto, l'appello resta per il verbale, e i punti tornano fra quelli per la prossima
 * assemblea. La prosecuzione di una seduta sospesa no: l'assemblea era già costituita.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('aperta la seconda e fatto l\'appello, finché non si vota si segna deserta: l\'assemblea è deserta, chi è in sala esce, i punti tornano per la prossima', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $a->punti()->where('numero', 3)->first()->forceFill(['chiave_fonte' => 'fattura:45'])->saveQuietly();
    $seconda = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'c30']);
    expect(ProposteDellOrdineDelGiorno::chiaviOccupate($s->c, null))->toContain('fattura:45');
    $riga = ViaRoma12::ultimaRiga();

    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $seconda->id], '2026-12-15 19:15'));

    $seconda->refresh();
    expect($r['esito'])->toBe('applicato')
        ->and($seconda->esito->value)->toBe('deserta')
        ->and($seconda->chiusa_il->format('H:i'))->toBe('18:15')
        ->and($a->refresh()->stato)->toBe(StatoAssemblea::DESERTA)
        ->and(PresenzaInSala::query()->where('seduta_id', $seconda->id)->whereNull('uscita_il')->count())->toBe(0)
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())->toBe(['Assemblea ordinaria del 15/12/2026: la seconda convocazione del 15/12/2026 è deserta: '
            . 'aperta alle 18:42, all\'appello 2 su 26 · 92,75 su 1.000,00: l\'assemblea è deserta'])
        ->and(ProposteDellOrdineDelGiorno::chiaviOccupate($s->c, null))->not->toContain('fattura:45');
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['seduta_id'])->toBeNull()->and($sala['da_aprire'])->toBeNull();
});

it('dopo il primo «Si vota» la seconda non si segna più deserta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seconda = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seconda->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $seconda->id], '2026-12-15 19:15')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Sul punto 1 si è già votato: la seconda convocazione non si segna più deserta. Chiudi l\'assemblea, o rinviala.')
        ->and($a->refresh()->stato)->toBe(StatoAssemblea::IN_CORSO);
});
