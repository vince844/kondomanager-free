<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * L'avviso senza nome (quello del fascicolo, una copia per tutti) ha anche lui il destinatario a destra, come gli avvisi per persona: «Ai condòmini del
 * Condominio …» con l'indirizzo del condominio. Prima la colonna di destra restava vuota.
 *
 * Che cosa non copre: l'aspetto (la prova a video); l'avviso per persona, che ha il nome e l'indirizzo della persona (`EmissioneDelleConvocazioniTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-11-26 10:00');
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questo computer.');
    }
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('l\'avviso senza nome ha a destra «Ai condòmini del Condominio …» con l\'indirizzo del condominio', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);
    $emissione = app(EmissioneDelleConvocazioni::class);

    $pdf = $emissione->rendi($a, [$emissione->avviso($a, ['nome' => '', 'indirizzo' => '', 'unita' => [], 'punti' => null])]);
    $file = tempnam(sys_get_temp_dir(), 'avviso');
    file_put_contents($file, $pdf);
    $testo = preg_replace('/\s+/', ' ', (string) shell_exec('pdftotext ' . escapeshellarg($file) . ' - 2>/dev/null'));
    @unlink($file);

    expect($testo)->toContain('Ai condòmini del')
        ->and($testo)->toContain('Gentili condòmini,');
    if (filled($s->c->indirizzo)) {
        expect(substr_count($testo, (string) $s->c->indirizzo))->toBeGreaterThanOrEqual(2);
    }
});
