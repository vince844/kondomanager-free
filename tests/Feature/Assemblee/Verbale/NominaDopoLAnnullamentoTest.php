<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\RigaDiNomina;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Services\Assemblee\Verbale\RegistroDiNominaERevoca;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Il registro di nomina e revoca dopo «Annulla la registrazione» (catena C8):
 *
 * - la riga «Nomina · verbale n. 42, punto 1» confermata, poi il 42 annullato: la riga dice che il verbale è annullato; registrato il verbale giusto come 43,
 *   la riga cita il 43, non più il 42;
 * - la stampa del registro tiene le righe confermate e poi annullate, con il perché (una riga si annulla, non si cancella); le proposte mai confermate no.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La conferma dell'amministratore votata e chiusa, il verbale generato. */
function ndaRiunione($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [['testo' => 'Conferma dell\'amministratore per il 2027', 'materia' => 'nomina']]), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', 'gallo', 'bianchi', ...SalaDiViaRoma12::altri()]);
    VerbaleDiViaRoma12::tuttiFavorevoli($t, $s, $a, 1, '2026-12-15 19:00');
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));

    return [$s, $a->refresh(), VerbaleDiViaRoma12::genera($t, $s, $a)];
}

it('la riga confermata dal n. 42, poi il 42 annullato e il verbale registrato come 43: la riga cita il 43', function () {
    [$s, $a, $v] = ndaRiunione($this);
    VerbaleDiViaRoma12::registra($this, $s, $v)->assertSessionHasNoErrors();
    $riga = RigaDiNomina::query()->where('stato', 'proposta')->firstOrFail();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.conferma', [$s->c, $riga]))->assertSessionHasNoErrors();
    expect($riga->refresh()->estremi)->toBe('verbale n. 42, punto 1');

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.annulla', [$s->c, $a, $v]), ['motivo' => 'Copia di un\'altra assemblea.'])
        ->assertSessionHasNoErrors();
    expect($riga->refresh()->estremi)->toContain('il verbale n. 42 è annullato');

    $nuovo = VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->orderByDesc('id')->firstOrFail();
    VerbaleDiViaRoma12::registra($this, $s, $nuovo)->assertSessionHasNoErrors();
    expect($nuovo->refresh()->numero)->toBe(43)
        ->and($riga->refresh()->estremi)->toBe('verbale n. 43, punto 1')->and($riga->verbale_id)->toBe($nuovo->id)
        ->and(RigaDiNomina::query()->where('stato', 'proposta')->count())->toBe(0);
});

it('la stampa del registro tiene le righe annullate con il perché; le proposte mai confermate no', function () {
    [$s, $a, $v] = ndaRiunione($this);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.store', $s->c), ['amministratore' => 'Andrea Costa', 'dal' => '2025-04-10',
        'da_dove' => 'carta', 'estremi' => 'verbale n. 36'])->assertSessionHasNoErrors();
    $aMano = RigaDiNomina::query()->where('estremi', 'verbale n. 36')->firstOrFail();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.annulla', [$s->c, $aMano]), ['motivo' => 'Data sbagliata.'])
        ->assertSessionHasNoErrors();
    VerbaleDiViaRoma12::registra($this, $s, $v)->assertSessionHasNoErrors();

    $stampa = app(RegistroDiNominaERevoca::class)->righePerLaStampa($s->c);
    expect(array_column($stampa, 'estremi'))->toBe(['verbale n. 36'])
        ->and($stampa[0])->toMatchArray(['stato' => 'annullata', 'annullata_motivo' => 'Data sbagliata.']);
});
