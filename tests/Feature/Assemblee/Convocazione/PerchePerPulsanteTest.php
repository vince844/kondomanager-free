<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * Decisione 647 (21/11/2026), punto 1: nel passo «Convocazione» ogni perché sta col suo pulsante. Il server dà la frase corta di ciò che spegne tutti e due i
 * pulsanti (il luogo o la piattaforma che mancano) in `comune`; la pagina la scrive una volta, «Lettere e PEC», e lascia «Solo le PEC» alla casella.
 *
 * Che cosa non copre: la riga scritta nella pagina (`SchedaConvocazione.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('senza il luogo la scheda dà il perché comune in breve, e quello lungo resta sulle lettere', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $a->forceFill(['luogo' => null])->save();
    $a->sedute()->update(['luogo' => null]);
    $this->actingAs($s->admin);

    $scheda = app(StatoDellaConvocazione::class)->perLaPagina($a->fresh());
    expect($scheda['comune']['perche'])->toBe('Manca il luogo della prima convocazione e della seconda convocazione (art. 66 c. 3). Scrivilo nel passo «Date e ordine del giorno».')
        ->and($scheda['comune']['lungo'])->toBe($scheda['lettere']['perche'])
        ->and($scheda['lettere']['attivo'])->toBeFalse();
});

it('con il luogo la scheda non ha un perché comune', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);

    expect(app(StatoDellaConvocazione::class)->perLaPagina($a)['comune'])->toBeNull();
});
