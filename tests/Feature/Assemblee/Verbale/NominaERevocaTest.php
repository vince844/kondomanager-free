<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoNomina;
use App\Models\Assemblee\RigaDiNomina;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Il registro di nomina e revoca (V9, decisione 595 (7), scena 7): la prima volta la nomina in corso (su carta, con gli estremi); registrato il verbale con la
 * delibera della materia «Nomina o revoca dell'amministratore» approvata, la riga proposta «Nomina: …, dal 15/12/2026, verbale n. 42, punto 1», che resta
 * «Proposta» e fuori dalla stampa finché non la confermi; «Correggi», «Annulla» con il perché; la delibera respinta non propone niente; il decreto con gli
 * estremi; annullata la registrazione, la proposta si annulla.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La riunione con la conferma dell'amministratore votata (approvata, o respinta) e chiusa; il verbale generato. */
function nerConferma($t, string $esito = 'approvata'): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [['testo' => 'Conferma dell\'amministratore per il 2027', 'materia' => 'nomina']]), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', 'gallo', 'bianchi', ...SalaDiViaRoma12::altri()]);
    $id = VerbaleDiViaRoma12::tuttiFavorevoli($t, $s, $a, 1, '2026-12-15 19:00', $esito === 'respinta' ? array_fill_keys(['rossi', 'marco', 'rita', 'gallo', 'bianchi',
        ...SalaDiViaRoma12::altri()], 'contrario') : []);
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));

    return [$s, $a->refresh(), VerbaleDiViaRoma12::genera($t, $s, $a)];
}

it('la nomina in corso la prima volta: su carta, con gli estremi; si annulla con il perché e non si cancella', function () {
    [$s] = nerConferma($this);
    expect(app(\App\Services\Assemblee\Verbale\RegistroDiNominaERevoca::class)->senzaLaNominaInCorso($s->c))->toBeTrue();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.store', $s->c), ['amministratore' => 'Andrea Costa', 'dal' => '2025-04-10',
        'da_dove' => 'carta', 'estremi' => 'verbale n. 36 del 10/04/2025, nel libro di carta'])->assertSessionHasNoErrors();
    $riga = RigaDiNomina::query()->firstOrFail();
    expect($riga->stato)->toBe(StatoNomina::CONFERMATA)->and($riga->evento->value)->toBe('nomina')
        ->and(app(\App\Services\Assemblee\Verbale\RegistroDiNominaERevoca::class)->senzaLaNominaInCorso($s->c))->toBeFalse();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.annulla', [$s->c, $riga]), ['motivo' => 'Il giorno era il 12/04.'])->assertSessionHasNoErrors();
    expect($riga->refresh()->stato)->toBe(StatoNomina::ANNULLATA)->and($riga->annullata_motivo)->toBe('Il giorno era il 12/04.')->and(RigaDiNomina::query()->count())->toBe(1);
});

it('registrato il verbale, la delibera di conferma propone la riga; resta «Proposta» e fuori dalla stampa finché non la confermi; si corregge prima', function () {
    [$s, $a, $verbale] = nerConferma($this);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.store', $s->c), ['amministratore' => 'Andrea Costa', 'dal' => '2025-04-10',
        'da_dove' => 'carta', 'estremi' => 'verbale n. 36'])->assertSessionHasNoErrors();
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();

    $proposta = RigaDiNomina::query()->where('stato', 'proposta')->firstOrFail();
    expect($proposta->only(['amministratore', 'da_dove', 'estremi']))->toBe(['amministratore' => 'Andrea Costa', 'da_dove' => 'verbale', 'estremi' => 'verbale n. 42, punto 1'])
        ->and($proposta->dal->format('Y-m-d'))->toBe('2026-12-15')->and($proposta->verbale_id)->toBe($verbale->id)
        ->and(collect(app(\App\Services\Assemblee\Verbale\RegistroDiNominaERevoca::class)->righe($s->c, true))->pluck('dal')->all())->toBe(['2025-04-10']);

    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.registro.nomine.update', [$s->c, $proposta]), ['amministratore' => 'Studio Costa Srl',
        'dal' => '2026-12-15', 'estremi' => 'verbale n. 42, punto 1'])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.conferma', [$s->c, $proposta]))->assertSessionHasNoErrors();
    expect($proposta->refresh()->stato)->toBe(StatoNomina::CONFERMATA)->and($proposta->amministratore)->toBe('Studio Costa Srl')
        ->and(collect(app(\App\Services\Assemblee\Verbale\RegistroDiNominaERevoca::class)->righe($s->c, true))->pluck('amministratore')->all())
        ->toBe(['Andrea Costa', 'Studio Costa Srl']);
});

it('la delibera respinta non propone niente; il decreto vuole gli estremi', function () {
    [$s, , $verbale] = nerConferma($this, 'respinta');
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    expect(RigaDiNomina::query()->count())->toBe(0);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.store', $s->c), ['amministratore' => 'Avv. Bruno', 'dal' => '2026-12-20',
        'da_dove' => 'decreto'])->assertSessionHasErrors(['decreto' => 'Scrivi gli estremi del decreto: l\'autorità, il numero e la data (art. 1130 n. 7 c.c.).']);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.store', $s->c), ['amministratore' => 'Avv. Bruno', 'dal' => '2026-12-20',
        'da_dove' => 'decreto', 'decreto' => ['autorita' => 'Tribunale di Milano', 'numero' => '1234/2026', 'data' => '2026-12-18']])->assertSessionHasNoErrors();
    expect(RigaDiNomina::query()->firstOrFail()->estremi)->toBe('decreto n. 1234/2026 del 18/12/2026, Tribunale di Milano');
});

it('annullata la registrazione, la riga proposta e non confermata si annulla', function () {
    [$s2, $a2, $v2] = nerConferma($this);
    VerbaleDiViaRoma12::registra($this, $s2, $v2)->assertSessionHasNoErrors();
    $this->actingAs($s2->admin)->post(route('admin.gestionale.assemblee.verbale.annulla', [$s2->c, $a2, $v2]), ['motivo' => 'Copia sbagliata.'])->assertSessionHasNoErrors();
    expect(RigaDiNomina::query()->where('verbale_id', $v2->id)->firstOrFail()->stato)->toBe(StatoNomina::ANNULLATA);
});
