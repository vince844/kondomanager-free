<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * La sala chiusa dice anche i testi (decisione 660, la prova a video del 22/11/2026, notte): la pagina della sala, a sala chiusa, porta con le presenze
 * dell'ultima seduta la discussione scritta in sala per ogni punto, letta dal verbale della riunione; le note del presidente, il motivo dell'esito e le
 * dichiarazioni a verbale c'erano già (i punti e le dichiarazioni della pagina). Chi guardava la sala chiusa credeva che quei testi fossero persi.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('a sala chiusa la pagina porta la discussione di ogni punto scritta in sala, con le note e le dichiarazioni', function () {
    [$s, $a, $seduta, $v] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $p4 = VerbaleDiViaRoma12::punto($a, 4);
    SalaDiViaRoma12::applica($this, $s, $a, ['gesto' => (string) \Illuminate\Support\Str::uuid(), 'tipo' => 'testo', 'dati' => ['seduta_id' => $seduta->id,
        'parte' => 'punto:' . $p4, 'documento' => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('L\'amministratore illustra i tre preventivi ricevuti.')])],
        'base' => 0] + array_intersect_key(SalaDiViaRoma12::gesto('x', [], '2026-12-15 19:50'), ['orologio' => 1, 'segnato_il' => 1]));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));

    $sala = $this->actingAs($s->admin)->getJson(route('admin.gestionale.assemblee.sala.stato', [$s->c, $a]))->assertOk()->json();
    expect($sala['seduta_id'])->toBeNull()->and($sala['ultima_seduta']['seduta_id'])->toBe((int) $seduta->id)
        ->and(StrutturaDelVerbale::testo($sala['ultima_seduta']['discussioni'][(string) $p4]['documento']))->toBe('L\'amministratore illustra i tre preventivi ricevuti.')
        // Solo i punti scritti: il punto 1 non ha una discussione.
        ->and($sala['ultima_seduta']['discussioni'])->not->toHaveKey((string) VerbaleDiViaRoma12::punto($a, 1));
    $quattro = collect($sala['punti'])->firstWhere('id', $p4);
    expect($quattro['votazioni'][0]['nota_non_votato'])->toBe('Russo Marco era in sala e non ha espresso il voto.')
        ->and(collect($sala['dichiarazioni'])->firstWhere('punto_id', $p4))->toMatchArray(['nome' => 'Rossi Mario', 'testo' => 'I preventivi sono arrivati il giorno prima dell\'assemblea.']);
});

it('a sala aperta la discussione resta dove la sala la scrive, nel verbale della seduta aperta', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $p4 = VerbaleDiViaRoma12::punto($a, 4);
    SalaDiViaRoma12::applica($this, $s, $a, ['gesto' => (string) \Illuminate\Support\Str::uuid(), 'tipo' => 'testo', 'dati' => ['seduta_id' => $seduta->id,
        'parte' => 'punto:' . $p4, 'documento' => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('Si discute del portone.')])],
        'base' => 0] + array_intersect_key(SalaDiViaRoma12::gesto('x', [], '2026-12-15 19:50'), ['orologio' => 1, 'segnato_il' => 1]));

    $sala = $this->actingAs($s->admin)->getJson(route('admin.gestionale.assemblee.sala.stato', [$s->c, $a]))->assertOk()->json();
    expect($sala['ultima_seduta'])->toBeNull()
        ->and(StrutturaDelVerbale::testo($sala['verbale']['discussioni'][(string) $p4]['documento']))->toBe('Si discute del portone.');
});
