<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\ProvaDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§4.4 del progetto della tappa 2): «Stampa la prova della convocazione». Per ogni convocato il mezzo, la spedizione, l'esito, il giorno che
 * conta, se è nei termini, la prova con la sua impronta e la riga del registro del suo esito con la sua impronta; in fondo chi manca e l'impronta della
 * stampa, che si rifà uguale dagli stessi dati.
 *
 * Che cosa non copre: l'aspetto del PDF (l'immagine nel rapporto).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la prova: chi è nei termini, chi no, chi manca, la riga del registro dell\'esito; l\'impronta della stampa si rifà uguale; la rotta dà un PDF', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $carla = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $gallo = ConvocazioneDiViaRoma12::invio($a, $s, 'gallo');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$carla->id, $gallo->id], 'canale' => 'raccomandata', 'data' => '2026-12-02']);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'in_giacenza', 'giorno' => '2026-12-07',
        'prova' => UploadedFile::fake()->create('avviso.pdf', 10, 'application/pdf')]);
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $gallo]), ['esito' => 'consegnata', 'giorno' => '2026-12-10']);

    $dati = app(ProvaDellaConvocazione::class)->dati($a);
    $righe = collect($dati['righe'])->keyBy(fn ($r) => explode(' (', $r['persona'])[0]);
    expect($righe['Neri Carla'])->toMatchArray(['mezzo' => 'Raccomandata', 'spedizione' => '02/12/2026', 'conta' => '07/12/2026', 'in_tempo' => 'sì'])
        ->and($righe['Neri Carla']['prova'])->toStartWith('avviso.pdf · ')
        ->and($righe['Neri Carla']['registro'])->toMatch('/^n\. \d+ · [0-9a-f]{12}$/')
        ->and($righe['Gallo Enzo']['in_tempo'])->toBe('no')
        ->and($righe['Bianchi Anna']['in_tempo'])->toBe('—')
        ->and($dati['mancano'])->toContain('Gallo Enzo: ricevuto il 10/12/2026, dopo il termine')
        ->and($dati['mancano'])->toContain('Bianchi Anna: senza un mezzo valido')
        ->and(app(ProvaDellaConvocazione::class)->dati($a)['impronta'])->toBe($dati['impronta']);

    $r = $this->post(route('admin.gestionale.assemblee.convocazione.prova', [$s->c, $a]));
    $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $r->getContent(), '%PDF'))->toBeTrue();
});
