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
 * 1.12.0-beta.13 (R18 e §7 del progetto della tappa 2): un id che arriva nell'indirizzo o nel corpo è del condominio e dell'assemblea della rotta. Un'assemblea
 * di Corso Italia 3 chiesta da Via Roma 12, un invio di un'altra assemblea, una persona di un altro condominio fra le «anagrafiche», un punto di un'altra
 * assemblea per «Ferma il preventivo»: 404, e niente si scrive.
 *
 * Che cosa non copre: le rotte delle pagine della preparazione (hanno il loro test).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('un\'assemblea, un invio, una persona o un punto di un altro condominio o di un\'altra assemblea danno 404', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $altro->admin = $s->admin;
    $diCorso = ViaRoma12::ordinaria($altro, [['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $estranea = $altro->persona('estranea');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $invio = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $seconda = ConvocazioneDiViaRoma12::ordinaria($s);
    $puntoDiCorso = $diCorso->punti()->first();

    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $diCorso]), ['formato' => 'pdf'])->assertNotFound();
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $seconda, $invio]), ['esito' => 'consegnata', 'giorno' => '2026-12-02'])->assertNotFound();
    $this->get(route('admin.gestionale.assemblee.invii.pdf', [$s->c, $seconda, $invio]))->assertNotFound();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $seconda]), ['formato' => 'pdf', 'anagrafiche' => [$estranea->id]])->assertNotFound();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $seconda]), ['invii' => [$invio->id], 'canale' => 'raccomandata', 'data' => '2026-12-02'])
        ->assertNotFound();
    $this->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]), ['punto_id' => $puntoDiCorso->id])->assertNotFound();
    $this->post(route('admin.gestionale.assemblee.invii.prova', [$s->c, $seconda, $invio]), ['prova' => UploadedFile::fake()->create('p.pdf', 5, 'application/pdf')])
        ->assertNotFound();
    expect(\App\Models\Gestionale\RegistroInvio::query()->where('documentabile_id', $seconda->id)->count())->toBe(0);
});
