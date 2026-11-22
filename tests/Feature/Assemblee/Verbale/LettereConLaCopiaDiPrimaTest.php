<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\RegistroInvio;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Le lettere della trasmissione stampate con la copia di prima (decisione 615 (6)): il 16/12 la lettera di Bianchi Anna stampata con la copia senza la
 * pagina 3, il 17/12 la copia sostituita. Il 18/12 «Registra la spedizione» la ferma: «1 lettera stampata con la copia di prima (Bianchi Anna): ristampale
 * prima di spedirle». Ristampata dopo la sostituzione, parte.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la lettera stampata prima di «Sostituisci la copia» non si spedisce: si ristampa, e poi parte', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $bianchi = $s->persone['bianchi']->id;
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$bianchi]])->assertOk();
    $vecchia = RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $bianchi)->firstOrFail();

    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.copia', [$s->c, $a, $verbale]), ['copia' => VerbaleDiViaRoma12::copia('verbale_completo.pdf'),
        'perche' => 'Mancava la pagina 3.'])->assertSessionHasNoErrors();

    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => [$vecchia->id], 'canale' => 'raccomandata',
        'data' => '2026-12-18'])->assertSessionHasErrors(['invii' => '1 lettera stampata con la copia di prima (Bianchi Anna): ristampale prima di spedirle.']);
    expect($vecchia->refresh()->stato)->toBe('stampata');

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$bianchi], 'di_nuovo' => true])->assertOk();
    $nuova = RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $bianchi)->orderByDesc('id')->firstOrFail();
    expect($nuova->id)->not->toBe($vecchia->id);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => [$nuova->id], 'canale' => 'raccomandata',
        'data' => '2026-12-18'])->assertSessionHasNoErrors();
});
