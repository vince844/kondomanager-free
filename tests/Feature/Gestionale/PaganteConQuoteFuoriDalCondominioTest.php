<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 207 — chi ha quote nelle rate di un piano del condominio si sceglie come pagante, anche fuori dal condominio.
 *
 * Il pagante era accettato se era fra le persone del condominio (il pivot) o se aveva una riga di titolarità in una sua unità. Una riga senza storia si
 * stacca con «Dissocia» anche con le quote emesse, e la rubrica toglie la persona dal condominio: le sue rate restavano, ma l'incasso la rifiutava, la
 * pagina non la offriva fra i paganti e l'estratto conto rispondeva 404. Il terzo criterio, una sola fonte (`Anagrafica::delCondominio`): ha quote nelle
 * rate di un piano di questo condominio.
 *
 * Il caso: Ugo, proprietario al 100 % dell'Interno 7, ha tre rate emesse da € 400,00 (Spese generali € 1.200,00). L'amministratore lo dissocia (la riga
 * non ha storia) e lo toglie dal condominio in rubrica.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\RataQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-06-15 10:00:00');
});

/** Ugo all'Interno 7 con le tre rate emesse; poi «Dissocia» dalla rotta e la rubrica. */
function pcqScenario($test, bool $fuori = true): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['7' => 1000])->titolare('7', 'ugo')->gestione('Ordinaria');
    app(\App\Services\CondominioService::class)->ensureDefaultConti($s->c);
    $s->voce('Ordinaria', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', numeroRate: 3, primaScadenza: '2026-02-05');
    $s->genera('Preventivo 2026');
    $s->emetti('Preventivo 2026', '2026-06-05')->assertSessionHasNoErrors();
    $s->cassa();
    expect(RataQuote::where('anagrafica_id', $s->persona('ugo')->id)->count())->toBe(3);
    if ($fuori) {
        $riga = DB::table('anagrafica_immobile')->where('anagrafica_id', $s->persona('ugo')->id)->value('id');
        $test->actingAs($s->admin)->delete(route('admin.gestionale.immobili.anagrafiche.destroy', [$s->c, $s->unita['7'], $riga]))->assertSessionHasNoErrors()->assertRedirect();
        DB::table('anagrafica_condominio')->where('anagrafica_id', $s->persona('ugo')->id)->where('condominio_id', $s->c->id)->delete();
        expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $s->persona('ugo')->id)->exists())->toBeFalse();
    }

    return $s;
}

function pcqIncassa($test, Beta51Scenario $s, string $chi, RataQuote $quota)
{
    return $test->actingAs($s->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s->c]), [
        'pagante_id' => $s->persona($chi)->id, 'cassa_id' => $s->cassa->id, 'data_pagamento' => '2026-06-15', 'importo_totale' => '400.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '400.00']],
    ]);
}

it('Coda 207 — Ugo dissociato e tolto dal condominio, con le rate emesse: la pagina dell\'incasso lo offre come pagante, l\'incasso della sua rata passa e l\'estratto conto risponde', function () {
    $s = pcqScenario($this);
    $ugo = $s->persona('ugo');

    $this->actingAs($s->admin)->get(route('admin.gestionale.movimenti-rate.create', [$s->c]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('condomini', fn ($c) => collect($c)->pluck('id')->contains($ugo->id))
            ->where('persone', fn ($c) => collect($c)->pluck('id')->contains($ugo->id)));

    $quota = RataQuote::where('anagrafica_id', $ugo->id)->orderBy('id')->firstOrFail();
    pcqIncassa($this, $s, 'ugo', $quota)->assertSessionHasNoErrors()->assertRedirect();
    expect(ScritturaContabile::where('condominio_id', $s->c->id)->where('tipo_movimento', 'incasso_rata')->count())->toBe(1)
        ->and((int) $quota->fresh()->importo_pagato)->toBe(40000);

    $this->actingAs($s->admin)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s->c, $ugo]))->assertOk();
    $this->actingAs($s->admin)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s->c, $ugo]))->assertOk();
});

it('Coda 207, guardia — una persona senza quote, senza righe e fuori dal condominio resta rifiutata come pagante, non è nella pagina e il suo estratto conto risponde 404', function () {
    $s = pcqScenario($this, fuori: false);
    $estraneo = Anagrafica::forceCreate(['nome' => 'Estraneo', 'email' => 'estraneo-pcq@test.it', 'indirizzo' => 'Via Altrove 1', 'codice_fiscale' => 'ESTRBCQ0000099999']);
    $quota = RataQuote::where('anagrafica_id', $s->persona('ugo')->id)->orderBy('id')->firstOrFail();

    $this->actingAs($s->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s->c]), [
        'pagante_id' => $estraneo->id, 'cassa_id' => $s->cassa->id, 'data_pagamento' => '2026-06-15', 'importo_totale' => '400.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '400.00']],
    ])->assertSessionHasErrors('pagante_id');
    $this->actingAs($s->admin)->get(route('admin.gestionale.movimenti-rate.create', [$s->c]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('condomini', fn ($c) => ! collect($c)->pluck('id')->contains($estraneo->id)));
    $this->actingAs($s->admin)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s->c, $estraneo]))->assertNotFound();
    $this->actingAs($s->admin)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s->c, $estraneo]))->assertNotFound();
});
