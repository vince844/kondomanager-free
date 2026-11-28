<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il coefficiente di una tabella collegata a una voce non può essere zero.
 *
 * Il dialogo «Associa tabella» lo dice già («compreso tra 1 e N%») e non manda uno zero; il server invece lo accettava (`min:0`), e il motore
 * salta in silenzio una tabella a coefficiente zero: la voce si ripartiva sulle sole altre tabelle, senza che nessuno l'avesse chiesto. Ora il
 * server rifiuta lo zero sulla casella del coefficiente, all'associazione e all'aggiornamento, e la tabella resta com'era.
 *
 * Lo scenario è quello di `Beta53BloccoDellaVoceTabelleTest`, con la voce «Spese generali» che nessun piano approvato tiene e un coefficiente
 * a 60, così che la somma dei coefficienti non sia la ragione del rifiuto.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const CZT_FRASE = 'Il coefficiente deve essere più di 0 e al massimo 100.';

it('un coefficiente zero si rifiuta all\'associazione e all\'aggiornamento della tabella, sulla sua casella, e le tabelle della voce restano', function (string $azione) {
    $s = b53bvScenario($this, ['pulizie' => '']);
    $parametri = [$s['c'], $s['e'], $s['pc'], $s['generali']];
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['generali']->id)->update(['coefficiente' => 60]);
    $prima = DB::table('conto_tabella_millesimale')->where('conto_id', $s['generali']->id)->get(['tabella_id', 'coefficiente'])->toArray();

    $risposta = match ($azione) {
        'associa' => $this->actingAs($this->user)->from('/piano-dei-conti')->post(route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', $parametri), [
            'tabella_millesimale_id' => $s['box']->id, 'coefficiente' => 0, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]),
        'aggiorna' => $this->actingAs($this->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.aggiorna-tabella', [...$parametri, $s['tabella']]), [
            'coefficiente' => 0, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]),
    };

    $risposta->assertRedirect('/piano-dei-conti')->assertSessionHasErrors(['coefficiente' => CZT_FRASE]);
    expect(DB::table('conto_tabella_millesimale')->where('conto_id', $s['generali']->id)->get(['tabella_id', 'coefficiente'])->toArray())->toEqual($prima);
})->with(['associa' => ['associa'], 'aggiorna' => ['aggiorna']]);

it('controprova — un coefficiente con i decimali sotto l\'uno passa, come nel dialogo', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);
    $parametri = [$s['c'], $s['e'], $s['pc'], $s['generali']];
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['generali']->id)->update(['coefficiente' => 60]);

    $this->actingAs($this->user)->from('/piano-dei-conti')->post(route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', $parametri), [
        'tabella_millesimale_id' => $s['box']->id, 'coefficiente' => 0.5, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
    ])->assertSessionHasNoErrors();

    expect(b53snFlash()['type'] ?? null)->toBe('success')
        ->and((float) DB::table('conto_tabella_millesimale')->where('conto_id', $s['generali']->id)->where('tabella_id', $s['box']->id)->value('coefficiente'))->toBe(0.5);
});
