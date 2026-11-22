<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Assemblee\Convocazione\UnioneDiPdf;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§3 P14 del progetto della tappa 2; scena 3 della bozza della pagina): la scheda «Fascicolo» con le righe della bozza (copertina e avviso
 * pronti, il preventivo da fermare, il punto senza documento che lo dice); «Componi il fascicolo» unisce la copertina, l'avviso e i documenti dei punti in un
 * PDF solo, con una riga nel registro; «Uno per persona (.zip)» un PDF per convocato; il tetto della memoria dei documenti che dice quale togliere.
 *
 * Che cosa non copre: il fascicolo del rendiconto fermato (le sue parti sono di `VersioniDelRendiconto`, qui si leggono); l'aspetto (l'immagine nel rapporto).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-11-26 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la scheda «Fascicolo»: copertina e avviso pronti, il preventivo da fermare con la frase, un punto senza documento che lo dice', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);
    $f = app(FascicoloDellAssemblea::class)->perLaPagina($a);

    expect($f['righe'][0])->toMatchArray(['numero' => '—', 'punto' => 'Copertina e avviso', 'stato' => ['parola' => 'Pronti', 'colore' => 'em']])
        ->and($f['righe'][2])->toMatchArray(['numero' => '2', 'azione' => 'ferma_preventivo'])
        ->and($f['righe'][4]['documento'])->toBe(FascicoloDellAssemblea::NESSUN_DOCUMENTO)
        ->and($f['frase'])->toBe('Il preventivo non è ancora fermato: lo fermi prima di spedire, così resta che cosa hanno ricevuto i condòmini.')
        ->and($f['per_persona']['quante'])->toBe(9);
});

it('«Componi il fascicolo» unisce copertina e avviso in un PDF solo con la riga del registro; lo .zip ha un PDF per persona', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $prima = ViaRoma12::ultimaRiga();
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.fascicolo.componi', [$s->c, $a]));
    $r->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Documenti', '2');
    expect(UnioneDiPdf::pagine((string) $r->getContent()))->toBeGreaterThanOrEqual(2)
        ->and(ConvocazioneDiViaRoma12::righeDelRegistro($prima)->last()->riepilogo)->toStartWith('Fascicolo composto: 2 documenti, impronta ');

    $zip = $this->post(route('admin.gestionale.assemblee.fascicolo.per-persona', [$s->c, $a]));
    $zip->assertOk()->assertHeader('Content-Type', 'application/zip');
    $z = new ZipArchive();
    $z->open($zip->baseResponse->getFile()->getPathname());
    expect($z->numFiles)->toBe(9);
});

it('oltre il tetto della memoria dei documenti il fascicolo non si compone e dice quale documento togliere', function () {
    expect(FascicoloDellAssemblea::MB_DEI_DOCUMENTI)->toBe(40);
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Il portone', 'materia' => 'lavori']]);
    $punto = $a->punti()->first();
    $archivio = app(ArchivioDocumenti::class);
    $percorso = $archivio->salvaContenuto('%PDF-1.4 ' . str_repeat('x', 41 * 1024 * 1024), 'pdf', 'documenti/prova');
    $doc = \App\Models\Documento::create(['name' => 'preventivi-portone.pdf', 'description' => 'Tre preventivi a confronto', 'path' => $percorso,
        'mime_type' => 'application/pdf', 'file_size' => 41 * 1024 * 1024, 'is_published' => false, 'is_approved' => true]);
    \App\Models\Assemblee\OggettoDelPunto::create(['punto_id' => $punto->id, 'oggetto_tipo' => 'documento', 'oggetto_id' => $doc->id, 'ruolo' => 'documento']);

    expect(fn () => app(FascicoloDellAssemblea::class)->documenti($a))->toThrow(\Illuminate\Validation\ValidationException::class);
});
