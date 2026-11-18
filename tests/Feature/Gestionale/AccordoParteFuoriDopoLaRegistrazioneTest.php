<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La scelta sulla parte della rata fuori dall'accordo di rientro (decisione 450) si cambia anche dopo la registrazione, dalla scheda dell'accordo: gli
 * accordi registrati prima di questa scelta hanno il di serie («continuano») senza che l'amministratore l'abbia vista, e una scelta sbagliata nella finestra
 * non si corregge chiudendo e rifacendo l'accordo (cambierebbe la data). Tocca solo la proposta degli interessi; il registro dell'accordo dice chi l'ha
 * cambiata, quando, da che cosa a che cosa.
 *
 * Il caso: Neri, rata 1 di € 300,00 scaduta il 31/03/2026, € 100,00 versati il 30/04; l'accordo del 15/07 copre € 150,00 dei € 200,00. Al 20/07, con
 * «continuano»: 39,452055 + 65,753425 + 5.000 × 6 giorni (15/07–20/07) 1,315068 = 106,520548 → € 1,07; con «sospesi» i soli giorni prima dell'accordo,
 * 105,205480 → € 1,05.
 */

use App\Models\Gestionale\AccordoRientro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** L'accordo del 15/07 su € 150,00 della rata 1 di Neri, con il di serie. */
function apdAccordo($test): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-04-30 10:00:00');
    lcdIncassa($test, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $test->travelTo('2026-07-15 12:00:00');
    $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '150,00']],
        'scadenze' => aclCalendario(['75,00', '75,00'], ['2026-07-31', '2026-08-31']), 'decadenza_rate' => 2, 'decadenza_giorni' => 15,
    ])->assertCreated();

    return [$s, AccordoRientro::latest('id')->firstOrFail()];
}

function apdCambia($test, array $s, AccordoRientro $a, mixed $scelta)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.parte-fuori', ['condominio' => $s['c']->id, 'accordo' => $a->id]),
        ['interessi_parte_fuori' => $scelta]);
}

function apdInteressi($test, array $s, string $alla): int
{
    return (int) accDi(accProposta($test, $s, ['Neri'], $alla)->assertOk()->json(), $s, 'Neri')['totale'];
}

it('dalla scheda la scelta passa a «sospesi»: la proposta la segue (€ 1,07 → € 1,05), e il registro dell\'accordo dice chi, quando, da che cosa a che cosa', function () {
    [$s, $a] = apdAccordo($this);
    $this->travelTo('2026-07-20 10:00:00');
    expect(apdInteressi($this, $s, '2026-07-20'))->toBe(107);

    apdCambia($this, $s, $a, 'sospesi')->assertOk()
        ->assertJsonPath('messaggio', 'Scelta cambiata: sulla parte della rata fuori dall\'accordo gli interessi restano sospesi finché l\'accordo regge. La proposta degli '
            . 'interessi la segue da adesso; gli interessi già registrati restano come sono.');
    $a = $a->fresh();
    expect($a->interessi_parte_fuori)->toBe('sospesi')
        ->and($a->registro)->toHaveCount(1)
        ->and(array_intersect_key($a->registro[0], array_flip(['cosa', 'da', 'a', 'utente_id'])))
        ->toBe(['cosa' => 'interessi_parte_fuori', 'da' => 'continuano', 'a' => 'sospesi', 'utente_id' => $this->user->id])
        ->and(apdInteressi($this, $s, '2026-07-20'))->toBe(105);

    // La scheda: la scelta di oggi e la riga del registro.
    $letto = aclLetto($this, $s)['accordi'][0];
    expect($letto['interessi_parte_fuori'])->toBe('sospesi')
        ->and($letto['registro'])->toBe(['Il 20/07/2026 alle 12:00 ' . $this->user->name . ' ha cambiato la scelta sulla parte della rata fuori dall\'accordo: da «gli interessi '
            . 'continuano» a «restano sospesi finché l\'accordo regge».']);

    // E si torna indietro: due righe nel registro.
    apdCambia($this, $s, $a, 'continuano')->assertOk();
    expect($a->fresh()->registro)->toHaveCount(2)->and(apdInteressi($this, $s, '2026-07-20'))->toBe(107);
});

it('la stessa scelta di prima, un valore che non c\'è, un accordo chiuso o di un altro condominio non cambiano niente', function () {
    [$s, $a] = apdAccordo($this);
    $this->travelTo('2026-07-20 10:00:00');
    apdCambia($this, $s, $a, 'continuano')->assertStatus(422)->assertJsonValidationErrors(['interessi_parte_fuori']);
    apdCambia($this, $s, $a, 'forse')->assertStatus(422)->assertJsonValidationErrors(['interessi_parte_fuori']);
    apdCambia($this, $s, $a, null)->assertStatus(422)->assertJsonValidationErrors(['interessi_parte_fuori']);

    $altro = accViaVerdi($this)['c'];
    $this->travelTo('2026-07-20 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.parte-fuori', ['condominio' => $altro->id, 'accordo' => $a->id]),
        ['interessi_parte_fuori' => 'sospesi'])->assertNotFound();

    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => $a->id]),
        ['chiuso_il' => '2026-07-20'])->assertOk();
    apdCambia($this, $s, $a, 'sospesi')->assertStatus(422)->assertJsonValidationErrors(['interessi_parte_fuori']);
    expect($a->fresh()->interessi_parte_fuori)->toBe('continuano')->and($a->fresh()->registro)->toBeNull();
});
