<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * La delega all'amministratore con il tetto (decisione 622 (3), la scena 4 della bozza approvata): i tre campi del punto («Fino a», «Per che cosa», «Fino
 * al») si scrivono dalla finestra del punto, con il tetto in euro che diventa centesimi una volta sola; la delibera è la delega; «Scegli la delibera» la mostra
 * con il tetto e la scadenza, e dopo la scadenza la mostra spenta, con il perché.
 *
 * Che cosa non copre: lo stralcio che la leggerà (la sua beta); la finestra a video (`FinestraDelPuntoDelega.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-11-26 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il menu di «Scegli la delibera» del condominio, la riga della delibera data. @return array<string, mixed> */
function dnpRiga($t, Beta51Scenario $s, int $delibera): array
{
    $gruppi = $t->actingAs($s->admin)->getJson(route('admin.gestionale.delibere.scegli', ['condominio' => $s->c->id, 'ruolo' => 'delibera']))->assertOk()->json('gruppi');

    return collect($gruppi)->flatMap(fn ($g) => $g['delibere'])->firstWhere('id', $delibera);
}

it('catena · il punto con la delega → la delibera → «Scegli la delibera» con il tetto e la scadenza → spenta dopo la scadenza', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), [
        'testo' => 'Delega all\'amministratore: accordi con i fornitori fino a € 2.000,00, fino al 31/12/2027', 'materia' => 'delega', 'importo' => '2.000,00',
        'dati' => ['delega' => ['ambito' => 'accordi_fornitori', 'fino_al' => '2027-12-31']],
    ])->assertSessionHasNoErrors();
    $punto = PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $a->id)->sole();
    // Il tetto da euro a centesimi una volta sola, al confine; per che cosa e fino a quando nei dati del punto.
    expect($punto->importo)->toBe(200000)
        ->and($punto->dati['delega'])->toBe(['ambito' => 'accordi_fornitori', 'fino_al' => '2027-12-31'])
        ->and($punto->maggioranza)->toBeNull();

    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, [(int) $punto->id => ['esito' => 'approvata']], '2026-12-15');
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    ConvocazioneDiViaRoma12::oggi('2027-03-01 10:00');
    $riga = dnpRiga($this, $s, $d->id);
    expect($riga['si_sceglie'])->toBeTrue()
        ->and($riga['delega'])->toMatchArray(['tetto' => 200000, 'ambito' => 'accordi_fornitori', 'fino_al' => '2027-12-31', 'in_vigore' => true,
            'frase' => '€ 2.000,00 fino al 31/12/2027']);

    // L'ultimo giorno si sceglie ancora; il giorno dopo la delega si vede spenta, con il perché.
    ConvocazioneDiViaRoma12::oggi('2027-12-31 18:00');
    expect(dnpRiga($this, $s, $d->id)['si_sceglie'])->toBeTrue();
    ConvocazioneDiViaRoma12::oggi('2028-01-02 10:00');
    $dopo = dnpRiga($this, $s, $d->id);
    expect($dopo['si_sceglie'])->toBeFalse()
        ->and($dopo['perche'])->toBe('scaduta il 31/12/2027')
        ->and($dopo['delega']['in_vigore'])->toBeFalse();
});

it('la delega si cambia e si toglie dal punto; per che cosa è solo una voce del programma, e il giorno si scrive com\'è', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Delega all\'amministratore', 'materia' => 'delega']]);
    $punto = $a->punti()->sole();
    $patch = fn (array $dati) => $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.punti.update', [$s->c, $a, $punto]), $dati);

    $patch(['materia' => 'delega', 'dati' => ['delega' => ['ambito' => 'spese_di_rappresentanza', 'fino_al' => '31/12/2027']]])
        ->assertSessionHasErrors(['dati.delega.ambito', 'dati.delega.fino_al' => 'Scegli il giorno fino a cui vale la delega.']);
    $patch(['materia' => 'delega', 'importo' => '1.500,00', 'dati' => ['delega' => ['ambito' => 'accordi_fornitori', 'fino_al' => '2027-06-30']]])->assertSessionHasNoErrors();
    expect($punto->fresh()->importo)->toBe(150000)->and($punto->fresh()->dati['delega'])->toBe(['ambito' => 'accordi_fornitori', 'fino_al' => '2027-06-30']);

    // Tolti tutti e due, la delega non c'è più nei dati del punto.
    $patch(['materia' => 'delega', 'dati' => ['delega' => ['ambito' => null, 'fino_al' => null]]])->assertSessionHasNoErrors();
    expect($punto->fresh()->dati['delega'] ?? null)->toBeNull();
});
