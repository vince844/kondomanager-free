<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Nel piano straordinario le voci del piano sono le fatture: «Sposta spesa» e il cestino della voce, che lavorano sulle righe di `piano_rate_capitoli`,
 * non si offrono e il server li rifiuta con una frase che dice che cosa fare. Prima la richiesta tornava con «L'elemento source id selezionato non è
 * valido.» (lo spostamento) e il cestino sganciava un conto con l'id della fattura, lasciando la fattura nel piano e dicendo «Voce rimossa e ricalcolata.».
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

it('la pagina del piano straordinario non offre «Sposta spesa»: nessuna sorgente e nessuna destinazione', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $piano = b52tPianoStraordinarioDaFattura($s, 100000);

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]));
    $r->assertOk();
    $props = $r->viewData('page')['props'];

    expect(collect($props['sources'])->all())->toBe([]);
    expect(collect($props['destinations'])->all())->toBe([]);
});

it('lo spostamento in un piano straordinario si rifiuta con la frase, e nessun movimento si scrive', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $piano = b52tPianoStraordinarioDaFattura($s, 100000);
    $fatturaId = (int) DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->value('fattura_passiva_id');

    $r = $this->actingAs($this->user)->from('/pagina-del-piano')->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $piano]), [
        'source_id' => $fatturaId, 'destination_id' => $s['spesa']->id, 'amount' => '100.00', 'reason' => 'Prova',
    ]);

    $r->assertRedirect('/pagina-del-piano');
    $r->assertSessionHasErrors(['piano' => 'Nei piani straordinari non si sposta budget: le voci del piano sono le fatture.']);
    expect(DB::table('budget_movements')->count())->toBe(0);
});

it('il cestino in un piano straordinario si rifiuta con la frase: la fattura resta nel piano, nessun conto si sgancia', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $piano = b52tPianoStraordinarioDaFattura($s, 100000);
    $fatturaId = (int) DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->value('fattura_passiva_id');
    // Un conto con lo stesso id della fattura, nel piano per caso: è quello che il cestino sganciava.
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $fatturaId, 'importo' => null, 'created_at' => now(), 'updated_at' => now()]);

    $r = $this->actingAs($this->user)->from('/pagina-del-piano')
        ->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano, $fatturaId]), ['accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova']);

    $r->assertRedirect('/pagina-del-piano');
    expect(session('message')['message'] ?? session('error') ?? null)
        ->toBe('Nei piani straordinari una fattura non si toglie da qui: elimina il piano e ricrealo con le fatture giuste.');
    expect(DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->count())->toBe(1);
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $fatturaId)->exists())->toBeTrue();
});
