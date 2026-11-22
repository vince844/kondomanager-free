<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * I controlli della ricevuta PEC stanno in un posto solo per la convocazione e per le lettere (`App\Services\Posta\RicevutaDellInvio`), con una regola in più
 * per tutte e due: la ricevuta non è prima del giorno dell'invio. Rossi Mario: la PEC della convocazione è partita il 26/11; una ricevuta del 25/11 è di
 * un'altra PEC, o il file è sbagliato, e la frase lo dice con le due date.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('una ricevuta datata prima della PEC non si legge come consegna: la frase dice le due date, e niente si scrive', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    ConvocazioneDiViaRoma12::oggi('2026-12-04 09:00');
    $rossi = ConvocazioneDiViaRoma12::invio($a, $s, 'rossi');

    $eml = (string) file_get_contents(base_path('tests/fixtures/pec/ricevuta_consegna_rossi.eml'));
    preg_match('/filename="daticert\.xml"\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+?)\r?\n\r?\n/', $eml, $m);
    $xml = str_replace('<giorno>03/12/2026</giorno>', '<giorno>25/11/2026</giorno>', (string) base64_decode($m[1]));
    $prima = str_replace($m[1], chunk_split(base64_encode($xml), 76, "\r\n"), $eml);

    $this->post(route('admin.gestionale.assemblee.invii.ricevuta', [$s->c, $a, $rossi]), [
        'ricevuta' => UploadedFile::fake()->createWithContent('ricevuta-consegna.eml', $prima)])
        ->assertSessionHasErrors(['ricevuta' => 'La ricevuta dice il 25/11/2026, prima della PEC, partita il 26/11/2026: controlla il file.']);

    expect($rossi->refresh()->esito)->toBeNull();
});
