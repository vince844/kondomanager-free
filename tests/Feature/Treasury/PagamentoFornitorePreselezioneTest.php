<?php

/**
 * «Paga» della tesoreria apre il pagamento con la fattura già scelta (`pagamenti-fornitori.create?fattura_id=…`). La fattura si legge nel condominio della
 * pagina: con il numero di una fattura di un altro condominio la pagina non sceglie il suo fornitore e non la propone.
 */

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/TesoreriaScenario.php';
require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function pfpFattura(array $s, int $fornitore, string $numero): int
{
    return DB::table('fatture_passive')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore, 'tipo_documento' => 'fattura',
        'numero_documento' => $numero, 'netto_a_pagare' => 10000, 'totale_documento' => 10000, 'importo_imponibile' => 10000, 'importo_iva' => 0, 'data_scadenza' => '2026-11-20',
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'is_pregresso' => false, 'data_documento' => '2026-11-01', 'created_at' => now(), 'updated_at' => now()]);
}

it('la fattura del condominio sceglie il suo fornitore; quella di un altro condominio non sceglie niente', function () {
    $s = tsrBase($this);
    $altro = tsrBase($this);
    $edil = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Edil Rossi srl', 'created_at' => now(), 'updated_at' => now()]);
    $ascensori = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Ascensori Spa', 'created_at' => now(), 'updated_at' => now()]);
    $sua = pfpFattura($s, $edil, '45/2026');
    $dellAltro = pfpFattura($altro, $ascensori, '12/2026');

    $props = fn (int $fattura) => $this->actingAs($this->user)->get(route('admin.gestionale.pagamenti-fornitori.create', [$s['c'], 'fattura_id' => $fattura]))->assertOk()
        ->viewData('page')['props'];

    expect($props($sua))->toMatchArray(['preselected_fattura_id' => $sua, 'preselected_fornitore_id' => $edil])
        ->and($props($dellAltro))->toMatchArray(['preselected_fattura_id' => null, 'preselected_fornitore_id' => null]);
});
