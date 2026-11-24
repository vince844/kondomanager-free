<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Coda 323) — il saldo del Wallet scritto a mano non si raddoppia con un doppio invio.
 *
 * Il controllo del posto («un saldo per persona, gestione, unità ed esercizio») sta nella richiesta; due invii quasi insieme lo passano
 * tutti e due, e la scrittura, fuori da ogni transazione, li accettava. Ora la scrittura ripete il controllo dentro una transazione,
 * dopo aver bloccato l'unità, con la stessa funzione e le stesse frasi della richiesta.
 *
 * Il «fra la validazione e la scrittura» si fa con un ascoltatore sulle query: appena la richiesta ha letto il posto libero, un secondo
 * invio (qui: la riga scritta a mano nel database) lo occupa.
 *
 * Cosa NON copre: il pulsante spento durante l'invio (`SaldiDetailPanelModaleStruttura.test.ts`); nessun indice unico (sarebbe una migrazione).
 */

use App\Models\Saldo;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

beforeEach(function () {
    b51aAccesso($this);
});

/** Il POST del pannello dei saldi per una persona: l'importo con il segno della colonna. */
function b14DoppioInvioPost($test, array $s, string $chi, int $centesimi)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $centesimi,
    ]);
}

/** Dopo la prima lettura del posto, scrive la riga di un altro invio: come se fosse arrivato nello stesso istante. */
function b14DoppioInvioAltroInvio(array $s, string $chi, int $centesimi): void
{
    $fatto = false;
    DB::listen(function ($q) use (&$fatto, $s, $chi, $centesimi) {
        if ($fatto || ! str_contains($q->sql, 'from "saldi"') || ! str_contains($q->sql, 'exists')) {
            return;
        }
        $fatto = true;
        Saldo::create([
            'esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'immobile_id' => $s['unita']->id, 'anagrafica_id' => $s['persone'][$chi]->id,
            'gestione_id' => $s['g']->id, 'saldo_iniziale' => $centesimi, 'origine' => 'manuale',
        ]);
    });
}

it('il posto occupato fra la validazione e la scrittura: rifiuto con la frase di sempre, un saldo solo', function () {
    $s = b51aDl7ConVendita($this);
    b14DoppioInvioAltroInvio($s, 'Ugo', -26820);

    b14DoppioInvioPost($this, $s, 'Ugo', -26820)->assertSessionHasErrors(['anagrafica_id' => 'Esiste già un saldo per questa anagrafica in questa gestione.']);

    expect(Saldo::where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->whereNull('subentro_id')->count())->toBe(1);
});

it('il posto libero: il saldo si scrive una volta (catena: il Wallet, poi il secondo invio rifiutato)', function () {
    $s = b51aDl7ConVendita($this);

    b14DoppioInvioPost($this, $s, 'Ugo', -26820)->assertSessionHasNoErrors();
    b14DoppioInvioPost($this, $s, 'Ugo', -26820)->assertSessionHasErrors(['anagrafica_id']);

    expect(Saldo::where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->whereNull('subentro_id')->count())->toBe(1);
});

it('il saldo solidale: stessa guardia, la frase del solidale', function () {
    $s = b51aDl7ConVendita($this);
    $fatto = false;
    DB::listen(function ($q) use (&$fatto, $s) {
        if ($fatto || ! str_contains($q->sql, 'from "saldi"') || ! str_contains($q->sql, 'exists')) {
            return;
        }
        $fatto = true;
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'immobile_id' => $s['unita']->id, 'anagrafica_id' => null,
            'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale']);
    });

    $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => null, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000,
    ])->assertSessionHasErrors(['anagrafica_id' => 'Esiste già un saldo solidale per questo immobile in questa gestione. Modifica quello esistente.']);

    expect(Saldo::where('immobile_id', $s['unita']->id)->whereNull('anagrafica_id')->whereNull('subentro_id')->count())->toBe(1);
});
