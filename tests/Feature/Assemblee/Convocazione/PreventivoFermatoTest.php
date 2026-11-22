<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\VersionePreventivo;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§0.4 del progetto della tappa 2): «Ferma il preventivo» dalla rotta. La versione 1 con le cifre in centesimi (le voci, il totale, il
 * riparto per tabella), l'impronta delle cifre, la distinta in PDF conservata con la sua impronta, il punto; fermarlo di nuovo fa la versione 2 e la prima
 * resta «sostituita»; la riga del registro; la scheda «Fascicolo» che dice «Fermato» e non chiede più di fermarlo.
 *
 * Che cosa non copre: le tre stampe del piano (si aggiungono quando il piano ordinario dell'esercizio c'è, e senza rate generate restano fuori);
 * l'approvazione dalla delibera (tappa 5).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-11-26 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('fermare il preventivo: le cifre in centesimi, l\'impronta, la distinta conservata, la versione dopo che sostituisce; la scheda dice «Fermato»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 240000, ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Assicurazione del fabbricato', 185050, ['Proprietà' => 100]);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Approvazione del preventivo 2026', 'materia' => 'preventivo', 'esercizio_id' => $s->e->id],
        ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $this->actingAs($s->admin);
    expect(app(FascicoloDellAssemblea::class)->perLaPagina($a)['frase'])->toBe(FascicoloDellAssemblea::FRASE_PREVENTIVO);
    $prima = ViaRoma12::ultimaRiga();

    $this->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]))->assertRedirect()->assertSessionHasNoErrors();
    $v = VersionePreventivo::query()->where('esercizio_id', $s->e->id)->firstOrFail();
    expect($v->numero)->toBe(1)->and($v->stato->value)->toBe('presentata')
        ->and($v->cifre['totale'])->toBe(425050)
        ->and(array_column($v->cifre['voci'], 'importo'))->toBe([240000, 185050])
        ->and($v->cifre['per_tabella'])->toBe([['tabella' => 'Proprietà', 'importo' => 425050]])
        ->and($v->impronta)->toBe(hash('sha256', json_encode($v->cifre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
        ->and($v->pdf[0]['parte'])->toBe('distinta');
    $doc = $v->documenti()->first();
    expect(hash('sha256', (string) app(ArchivioDocumenti::class)->leggi($doc->path)))->toBe($v->pdf[0]['impronta'])
        ->and(ConvocazioneDiViaRoma12::righeDelRegistro($prima)->last()->riepilogo)->toStartWith('Preventivo 2026 fermato per l\'assemblea: versione 1, impronta ');
    $scheda = app(FascicoloDellAssemblea::class)->perLaPagina($a);
    expect($scheda['frase'])->toBeNull()->and($scheda['righe'][1]['stato'])->toBe(['parola' => 'Fermato', 'colore' => 'em']);

    $this->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]))->assertRedirect();
    expect(VersionePreventivo::query()->where('esercizio_id', $s->e->id)->orderBy('numero')->pluck('stato')->map->value->all())->toBe(['sostituita', 'presentata']);
});

it('un preventivo senza voci non si ferma, e lo dice', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Approvazione del preventivo 2026', 'materia' => 'preventivo', 'esercizio_id' => $s->e->id]]);
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]))->assertStatus(422)
        ->assertJsonPath('errors.punto.0', \App\Services\Assemblee\Convocazione\VersioniDelPreventivo::SENZA_VOCI);
});
