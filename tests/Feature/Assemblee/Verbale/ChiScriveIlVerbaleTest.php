<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Chi scrive il verbale (V6, scena 13): una persona alla volta per parte, nella cache; chi legge vede chi scrive e lo chiede; chi scrive risponde «Lascia
 * scrivere» o «Continuo io»; senza risposta in 60 secondi la parte passa se chi scriveva non ha scritto, altrimenti la richiesta scade; chi non scrive per un
 * minuto e mezzo lascia la parte libera; due punti diversi si scrivono insieme. È un aiuto, non una serratura: il salvataggio non lo controlla.
 *
 * Che cosa non copre: la lettura periodica della pagina a video.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** @return array{0: Beta51Scenario, 1: \App\Models\Assemblee\Assemblea, 2: \App\Models\Assemblee\VerbaleDellAssemblea, 3: \App\Models\User} */
function csvDue($t): array
{
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($t);
    $verbale = VerbaleDiViaRoma12::genera($t, $s, $a);
    $marco = ViaRoma12::utente([\App\Enums\Permission::VIEW_ASSEMBLEE, \App\Enums\Permission::MANAGE_ASSEMBLEE, \App\Enums\Permission::ACCESS_ADMIN_PANEL]);
    $marco->forceFill(['name' => 'Marco Galli'])->save();

    return [$s, $a, $verbale, $marco];
}

function csvPost($t, $chi, Beta51Scenario $s, $verbale, string $rotta, array $dati): array
{
    return $t->actingAs($chi)->postJson(route('admin.gestionale.assemblee.verbale.' . $rotta, [$s->c, $verbale->assemblea_id, $verbale->id]), $dati)->assertOk()->json();
}

it('chi scrive prende la parte; chi apre dopo legge e vede chi scrive; due punti diversi si scrivono insieme', function () {
    [$s, , $verbale, $marco] = csvDue($this);
    $s->admin->forceFill(['name' => 'Andrea Costa'])->save();
    $andrea = csvPost($this, $s->admin, $s, $verbale, 'scrivo', ['parte' => 'intero', 'dispositivo' => 'portatile']);
    $marcoLegge = csvPost($this, $marco, $s, $verbale, 'scrivo', ['parte' => 'intero', 'dispositivo' => 'computer di Marco']);
    $altroPunto = csvPost($this, $marco, $s, $verbale, 'scrivo', ['parte' => 'punto:7', 'dispositivo' => 'computer di Marco']);

    expect($andrea['scrive'])->toMatchArray(['nome' => 'Andrea Costa', 'sono_io' => true])
        ->and($marcoLegge['scrive'])->toMatchArray(['nome' => 'Andrea Costa', 'sono_io' => false])
        ->and(collect($marcoLegge['aperto_da'])->pluck('nome')->all())->toContain('Andrea Costa')
        ->and($altroPunto['scrive'])->toMatchArray(['nome' => 'Marco Galli', 'sono_io' => true]);
});

it('«Chiedi di scrivere» → «Lascia scrivere»: scrive chi ha chiesto; «Continuo io»: resta, e chi ha chiesto lo vede', function () {
    [$s, , $verbale, $marco] = csvDue($this);
    csvPost($this, $s->admin, $s, $verbale, 'scrivo', ['parte' => 'intero', 'dispositivo' => 'portatile']);
    $chiede = csvPost($this, $marco, $s, $verbale, 'chiedi', ['parte' => 'intero', 'dispositivo' => 'computer di Marco']);
    expect($chiede['richiesta'])->toMatchArray(['mia' => true, 'secondi' => 60]);
    $perAndrea = app(\App\Services\Assemblee\Verbale\ChiScriveIlVerbale::class)->stato($verbale, 'intero', 'portatile');
    expect($perAndrea['richiesta'])->toMatchArray(['nome' => $marco->name, 'per_me' => true]);

    $dopo = csvPost($this, $s->admin, $s, $verbale, 'rispondi', ['parte' => 'intero', 'dispositivo' => 'portatile', 'risposta' => 'lascia']);
    expect($dopo['scrive'])->toMatchArray(['nome' => $marco->name, 'sono_io' => false]);

    csvPost($this, $s->admin, $s, $verbale, 'chiedi', ['parte' => 'intero', 'dispositivo' => 'portatile']);
    csvPost($this, $marco, $s, $verbale, 'rispondi', ['parte' => 'intero', 'dispositivo' => 'computer di Marco', 'risposta' => 'continuo']);
    $esito = app(\App\Services\Assemblee\Verbale\ChiScriveIlVerbale::class)->stato($verbale, 'intero', 'portatile');
    expect($esito['scrive']['nome'])->toBe($marco->name)->and($esito['esito_della_richiesta']['esito'])->toBe('continua');
});

it('senza risposta in 60 secondi: passa se chi scriveva non ha scritto, scade se stava scrivendo; senza scrivere per 90 secondi la parte è libera', function () {
    [$s, $a, $verbale, $marco] = csvDue($this);
    $chi = app(\App\Services\Assemblee\Verbale\ChiScriveIlVerbale::class);
    csvPost($this, $s->admin, $s, $verbale, 'scrivo', ['parte' => 'intero', 'dispositivo' => 'portatile']);
    csvPost($this, $marco, $s, $verbale, 'chiedi', ['parte' => 'intero', 'dispositivo' => 'computer di Marco']);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:01:05');
    expect($chi->stato($verbale, 'intero', 'computer di Marco')['scrive']['sono_io'])->toBeTrue();

    // Andrea scrive mentre Marco aspetta: la richiesta scade.
    csvPost($this, $s->admin, $s, $verbale, 'chiedi', ['parte' => 'intero', 'dispositivo' => 'portatile']);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:01:30');
    $chi->haScritto($verbale, 'intero', 'computer di Marco', $marco);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:02:40');
    $esito = $chi->stato($verbale, 'intero', 'portatile');
    expect($esito['scrive']['nome'])->toBe($marco->name)->and($esito['esito_della_richiesta']['esito'])->toBe('scaduta');

    // Un minuto e mezzo senza scrivere: la parte torna libera.
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:04:10');
    expect($chi->stato($verbale, 'intero', 'portatile')['scrive'])->toBeNull();
});

it('è un aiuto, non una serratura: il salvataggio di chi non ha la parte arriva lo stesso', function () {
    [$s, $a, $verbale, $marco] = csvDue($this);
    csvPost($this, $s->admin, $s, $verbale, 'scrivo', ['parte' => 'intero', 'dispositivo' => 'portatile']);
    $r = $this->actingAs($marco)->postJson(route('admin.gestionale.assemblee.verbale.gesti', [$s->c, $a]), ['dispositivo' => 'computer di Marco',
        'gesti' => [VerbaleDiViaRoma12::testo($verbale->id, 'intero', \App\Services\Assemblee\Verbale\StrutturaDelVerbale::documento([
            \App\Services\Assemblee\Verbale\StrutturaDelVerbale::paragrafo('Marco scrive senza chiedere.')]), '2026-12-15 20:59', 1)]])->json('risposte.0');
    expect($r['esito'])->toBe('applicato');
});
