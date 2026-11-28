<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La fattura annullata dopo che il suo piano ha incassato, e la fattura nuova dello stesso fornitore: il carrello avvisa sulla fattura nuova che il piano
 * chiede ancora quella annullata, perché messa a rate per intero i condòmini pagherebbero due volte la stessa spesa. Il programma non sa se la nuova la
 * sostituisce: lo dice, e decide l'amministratore.
 *
 * Il caso: «Manutenzione tetto» a € 0 nel preventivo, la fattura ZM0001 da € 1.400,00 di Coperture Rossi nel piano «Tetto a rate», la prima rata emessa e
 * pagata da Bice; la ZM0001 annullata (come con le versioni di prima, che lo permettevano) e la ZM0002 da € 1.400,00 registrata al suo posto.
 */

use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta15VoceAZeroScenari.php';

uses(RefreshDatabase::class);

function fcsCarrello($t, array $r): \Illuminate\Support\Collection
{
    return collect($t->actingAs($r['s']->admin)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', ['condominio' => $r['s']->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $r['s']->e->id]))->assertOk()->json());
}

function fcsIncassato($t, array $r, PianoRate $piano): void
{
    $s = $r['s'];
    b15vzStato($t, $r, $piano, true)->assertSessionHasNoErrors();
    $prima = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->first();
    $t->actingAs($s->admin)->post(route('admin.gestionale.piani-rate.emetti', [$s->c, $piano]), [
        'rate_ids' => [$prima->id], 'data_emissione' => '2026-11-30', 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
    $quota = DB::table('rate_quote')->where('rata_id', $prima->id)->where('anagrafica_id', $s->persona('bice')->id)->first();
    $euro = number_format($quota->importo / 100, 2, '.', '');
    $t->actingAs($s->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s->c]), [
        'pagante_id' => $s->persona('bice')->id, 'cassa_id' => $s->cassa()->id, 'data_pagamento' => '2026-12-01', 'importo_totale' => $euro,
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => $euro]],
    ])->assertSessionHasNoErrors();
    expect($piano->fresh()->haIncassiRegistrati())->toBeTrue();
}

it('la fattura nuova dello stesso fornitore porta l\'avviso con il nome del piano e il numero della fattura annullata', function () {
    $r = b15vzTetto($this);
    $vecchia = b15vzFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b15vzPianoFatture($this, $r, $vecchia, '1.400,00')->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    fcsIncassato($this, $r, $piano);
    DB::table('fatture_passive')->where('id', $vecchia->id)->update(['stato_pagamento' => 'stornata']);
    b15vzFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0002', 140000);

    $nuova = fcsCarrello($this, $r)->firstWhere('numero_documento', 'ZM0002');
    expect($nuova)->not->toBeNull()
        ->and((string) ($nuova['avviso_sostituzione'] ?? ''))->toBe('Il piano «Tetto a rate» chiede ancora la fattura n. ZM0001, annullata: se questa la '
            . 'sostituisce, mettila a rate per la sola differenza, o correggi prima quel piano.');
});

it('senza un piano che ha incassato, o con un altro fornitore, nessun avviso', function () {
    $r = b15vzTetto($this);
    $vecchia = b15vzFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b15vzPianoFatture($this, $r, $vecchia, '1.400,00')->assertSessionHasNoErrors();
    DB::table('fatture_passive')->where('id', $vecchia->id)->update(['stato_pagamento' => 'stornata']);
    b15vzFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0002', 140000);
    expect(fcsCarrello($this, $r)->firstWhere('numero_documento', 'ZM0002')['avviso_sostituzione'] ?? null)->toBeNull();
});
