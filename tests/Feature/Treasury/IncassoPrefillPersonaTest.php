<?php

/**
 * «Registra l'incasso» della tesoreria apre l'incasso con la persona già scelta (`movimenti-rate.create?prefill_anagrafica_id=…`, letto dalla pagina). Una
 * persona di un altro condominio non si sceglie: non è fra le persone della pagina, la situazione debitoria non ha le sue rate, e il salvataggio la rifiuta.
 * Con il solo `prefill_anagrafica_id` la pagina non scrive niente.
 */

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

it('la persona di un altro condominio non è fra quelle della pagina, non ha rate nella situazione debitoria, e il salvataggio la rifiuta', function () {
    $s = smaViaVerdi($this);
    $altro = lcdScenario(['A' => ['ugo', 1000]], 1, 'prima_rata');
    $ugo = $altro['persone']['ugo'];
    $scritture = DB::table('scritture_contabili')->count();

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.create', [$s['c'], 'prefill_anagrafica_id' => $ugo->id]))->assertOk()
        ->viewData('page')['props'];
    expect(collect($props['persone'])->pluck('id')->all())->not->toContain($ugo->id)
        ->and(collect($props['condomini'])->pluck('id')->all())->not->toContain($ugo->id)
        ->and(collect($props['persone'])->pluck('id')->all())->toContain($s['persone']['rossi']->id);

    $situazione = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [$s['c'], 'anagrafica_id' => $ugo->id]))->assertOk()->json();
    expect(collect($situazione['rate'] ?? $situazione['data'] ?? [])->all())->toBe([]);

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $ugo->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-05-20', 'importo_totale' => '10.00', 'descrizione' => 'Bonifico', 'eccedenza' => '0.00',
        'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [],
    ])->assertSessionHasErrors();
    expect(DB::table('scritture_contabili')->count())->toBe($scritture);
});
