<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il versamento dell'accordo di rientro e l'ordine con un accordo in corso, sul caso di Neri (`AccordoCalendarioScenario.php`) con la rata 2 (€ 300,00,
 * 30/06) emessa e fuori dall'accordo: la rata corrente.
 *
 * - «Versamento dell'accordo» va alle rate che l'accordo copre: prima gli interessi e le spese, poi il capitale più vecchio (art. 1194 c.c.). € 75,31 il
 *   31/07: interessi € 0,92, sollecito € 25,00, rata 1 € 49,39 (75,31 − 0,92 − 25,00). L'incasso porta la dichiarazione `accordo_rientro`, e la ricevuta
 *   lo dice riga per riga.
 * - Con un accordo in corso le rate e gli accessori coperti escono dall'ordine proposto: un versamento senza la scelta va alla rata corrente. € 300,00 sulla
 *   rata 2 sono «secondo l'ordine», anche con gli interessi, il sollecito e la rata 1 più vecchi. Denaro sulle rate dell'accordo senza la scelta è fuori
 *   dall'ordine, e il modulo lo sa.
 */

use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** L'esito della rotta di sola lettura dell'ordine, per una distribuzione [id quota => centesimi]. */
function vdaOrdine($test, array $s, array $righe, string $data, bool $dellAccordo, int $parteInPiu = 0): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.movimenti-rate.ordine', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data,
        'importo_totale' => number_format((array_sum($righe) + $parteInPiu) / 100, 2, '.', ''), 'eccedenza' => number_format($parteInPiu / 100, 2, '.', ''),
        'gestione_id' => $s['g']->id,
        'versamento_accordo' => $dellAccordo ? '1' : '0',
        'dettaglio_pagamenti' => array_map(fn ($id, $cent) => ['rata_id' => (int) $id, 'importo' => number_format($cent / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ])->assertOk()->json();
}

it('il versamento dell\'accordo di € 75,31 va a interessi € 0,92, sollecito € 25,00 e rata 1 € 49,39, con la dichiarazione e la ricevuta riga per riga', function () {
    [$s, $interessi, $sollecito] = aclAccordo($this, rata2: true);
    $this->travelTo('2026-07-31 10:00:00');
    $qi = (int) adbQuota($interessi)->id;
    $qs = (int) adbQuota($sollecito)->id;
    $rata1 = lcdQuota($s, 'Neri', 1);
    $rata2 = lcdQuota($s, 'Neri', 2);

    // La situazione debitoria: per ogni riga la parte nell'accordo, e la posizione nell'ordine dell'accordo (prima gli accessori, poi il capitale).
    $risposta = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [$s['c'], 'anagrafica_id' => $s['persone']['Neri']->id]))->assertOk()->json();
    $righe = collect($risposta['rate'])->keyBy('id');
    expect([$righe[$qi]['in_accordo'], $righe[$qs]['in_accordo'], $righe[$rata1]['in_accordo'], $righe[$rata2]['in_accordo']])->toBe([0.92, 25, 200, 0])
        ->and([$righe[$qi]['ordine_accordo'], $righe[$qs]['ordine_accordo'], $righe[$rata1]['ordine_accordo'], $righe[$rata2]['ordine_accordo']])->toBe([1, 2, 3, null])
        ->and($risposta['accordo'])->toMatchArray(['data_accordo' => '2026-07-15', 'in_accordo' => 22592, 'prossima' => ['numero' => 1, 'data' => '2026-07-31', 'mancano' => 7531]]);

    $distribuzione = [$qi => 92, $qs => 2500, $rata1 => 4939];
    expect(vdaOrdine($this, $s, $distribuzione, '2026-07-31', true)['esito'])->toBe(OrdineImputazione::ACCORDO_RIENTRO);

    $incasso = aclVersa($this, $s, $distribuzione, '2026-07-31');
    expect(ScritturaContabile::find($incasso)->imputazione)->toBe(OrdineImputazione::ACCORDO_RIENTRO)
        ->and((int) DB::table('rate_quote')->where('id', $rata1)->selectRaw('importo - importo_pagato as r')->value('r'))->toBe(15061);
    $ricevuta = app(RicevutaIncasso::class)->per(ScritturaContabile::findOrFail($incasso));
    expect(array_map(fn ($r) => [$r['rata'], $r['imputato']], $ricevuta['quote']))->toBe([
        ['Interessi di mora al 29/06/2026', 92], ['Spese di sollecito', 2500], ['Rata 1', 4939],
    ])->and($ricevuta['imputazione'])->toBe('Imputazione: versamento dell\'accordo di rientro.')
        ->and(OrdineImputazione::etichetta(OrdineImputazione::ACCORDO_RIENTRO))->toBe('Versamento dell\'accordo di rientro');
});

it('con un accordo in corso un versamento senza la scelta va alla rata corrente: € 300,00 sulla rata 2 sono «secondo l\'ordine»', function () {
    [$s, $interessi, $sollecito] = aclAccordo($this, rata2: true);
    $this->travelTo('2026-07-31 10:00:00');
    $rata2 = lcdQuota($s, 'Neri', 2);

    // L'ordine proposto: la rata 2 per prima fra quelle che restano aperte fuori dall'accordo.
    $aperte = app(OrdineImputazione::class)->rateAperte((int) $s['c']->id, (int) $s['persone']['Neri']->id);
    $fuori = array_values(array_filter($aperte, fn ($r) => $r['aperto'] - $r['in_accordo'] > 0));
    expect($fuori[0]['rata_id'])->toBe(lcdRata($s, 2))
        ->and(collect($aperte)->firstWhere('rata_id', $interessi)['in_accordo'])->toBe(92);

    expect(vdaOrdine($this, $s, [$rata2 => 30000], '2026-07-31', false)['esito'])->toBe(OrdineImputazione::ORDINE_SCADENZE);
    $incasso = aclVersa($this, $s, [$rata2 => 30000], '2026-07-31', false);
    expect(ScritturaContabile::find($incasso)->imputazione)->toBe(OrdineImputazione::ORDINE_SCADENZE)
        // Gli accessori e la rata 1 non sono toccati.
        ->and((int) DB::table('rate_quote')->where('id', adbQuota($interessi)->id)->value('importo_pagato'))->toBe(0)
        ->and((int) DB::table('rate_quote')->where('id', adbQuota($sollecito)->id)->value('importo_pagato'))->toBe(0);
});

it('denaro sulle rate dell\'accordo senza la scelta è fuori dall\'ordine; con la scelta, denaro sulla rata corrente è fuori dall\'accordo', function () {
    [$s, $interessi] = aclAccordo($this, rata2: true);
    $this->travelTo('2026-07-31 10:00:00');
    $qi = (int) adbQuota($interessi)->id;
    $rata2 = lcdQuota($s, 'Neri', 2);

    $senza = vdaOrdine($this, $s, [$qi => 92], '2026-07-31', false);
    expect($senza)->toMatchArray(['esito' => OrdineImputazione::FUORI_ORDINE, 'nell_accordo' => true, 'fuori_dall_accordo' => false])
        ->and($senza['rata_aperta']['rata_id'])->toBe(lcdRata($s, 2));

    $con = vdaOrdine($this, $s, [$rata2 => 30000], '2026-07-31', true);
    expect($con)->toMatchArray(['esito' => OrdineImputazione::FUORI_ORDINE, 'nell_accordo' => false, 'fuori_dall_accordo' => true])
        ->and($con['rata_aperta']['descrizione'])->toBe('Interessi di mora al 29/06/2026');

    // Il capitale prima degli accessori, dentro l'accordo: fuori dall'ordine dell'accordo.
    $capitale = vdaOrdine($this, $s, [lcdQuota($s, 'Neri', 1) => 5000], '2026-07-31', true);
    expect($capitale['esito'])->toBe(OrdineImputazione::FUORI_ORDINE)->and($capitale['rata_aperta']['descrizione'])->toBe('Interessi di mora al 29/06/2026');

    // Registrato con la dichiarazione dell'amministratore, si segna come sempre.
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-07-31', 'importo_totale' => '0.92', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $qi, 'importo' => '0.92']],
        'imputazione_scelta' => OrdineImputazione::SCELTA_AMMINISTRATORE,
    ])->assertSessionHasNoErrors();
    expect(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->orderByDesc('id')->first()->imputazione)->toBe(OrdineImputazione::SCELTA_AMMINISTRATORE)
        // Senza la scelta, il denaro arrivato su una rata che l'accordo copre per intero conta per l'accordo lo stesso: la rata 1 del calendario ha € 0,92.
        ->and(aclRate(aclLetto($this, $s))[0])->toBe([1, 92, 7531 - 92, false]);
});

it('senza un accordo in corso l\'ordine è quello di sempre, e la situazione debitoria non ha un accordo', function () {
    [$s, $interessi] = aclNeri($this, rata2: true);
    $this->travelTo('2026-07-31 10:00:00');
    $risposta = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [$s['c'], 'anagrafica_id' => $s['persone']['Neri']->id]))->assertOk()->json();
    expect($risposta['accordo'])->toBeNull()
        ->and(collect($risposta['rate'])->pluck('in_accordo')->unique()->values()->all())->toBe([0])
        ->and(vdaOrdine($this, $s, [(int) adbQuota($interessi)->id => 92], '2026-07-31', false)['esito'])->toBe(OrdineImputazione::ORDINE_SCADENZE);
});

it('il versamento dell\'accordo con una parte in più: la parte oltre l\'accordo segue l\'ordine di sempre, sulla rata 2 scaduta; a credito con la rata 2 aperta è fuori ordine', function () {
    [$s, $interessi, $sollecito] = aclAccordo($this, rata2: true);
    $this->travelTo('2026-07-31 10:00:00');
    $accordo = [(int) adbQuota($interessi)->id => 92, (int) adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 20000];
    // € 300,00: € 225,92 all'accordo (0,92 + 25,00 + 200,00), € 74,08 in più. A credito, con la rata 2 (30/06) aperta: fuori ordine.
    $aCredito = vdaOrdine($this, $s, $accordo, '2026-07-31', true, 7408);
    expect($aCredito)->toMatchArray(['esito' => OrdineImputazione::FUORI_ORDINE, 'parte_in_piu' => true, 'fuori_dall_accordo' => false])
        ->and($aCredito['rata_aperta']['rata_id'])->toBe(lcdRata($s, 2));
    // Sulla rata 2: segue l'ordine, ed è un versamento dell'accordo.
    expect(vdaOrdine($this, $s, $accordo + [lcdQuota($s, 'Neri', 2) => 7408], '2026-07-31', true)['esito'])->toBe(OrdineImputazione::ACCORDO_RIENTRO);
    $incasso = aclVersa($this, $s, $accordo + [lcdQuota($s, 'Neri', 2) => 7408], '2026-07-31');
    expect(ScritturaContabile::find($incasso)->imputazione)->toBe(OrdineImputazione::ACCORDO_RIENTRO)
        ->and((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'Neri', 2))->value('importo_pagato'))->toBe(7408)
        ->and(array_column(aclLetto($this, $s)['accordi'][0]['calendario'], 'versata'))->toBe([true, true, true]);
});

/** Il caso con U3 di Neri sulla rata 1, fuori dall'accordo (€ 70,00), accanto a U1 nell'accordo (€ 200,00): [scenario, interessi, sollecito, quota U1, quota U3]. */
function vdaDueUnita($test): array
{
    [$s, $interessi, $sollecito] = aclNeri($test);
    $rata1 = lcdRata($s, 1);
    $neri = (int) $s['persone']['Neri']->id;
    $u3 = (int) $s['unita']['U3']->id;
    DB::table('rate_quote')->where('rata_id', $rata1)->where('immobile_id', $u3)->update(['anagrafica_id' => $neri]);
    DB::table('righe_scritture')->where('rata_id', $rata1)->where('immobile_id', $u3)->update(['anagrafica_id' => $neri]);
    aclRegistra($test, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario())->assertCreated();

    return [$s, $interessi, $sollecito, (int) DB::table('rate_quote')->where('rata_id', $rata1)->where('immobile_id', $s['unita']['U1']->id)->value('id'),
        (int) DB::table('rate_quote')->where('rata_id', $rata1)->where('immobile_id', $u3)->value('id')];
}

it('due unità sulla stessa rata: senza la scelta i € 70,00 vanno a U3, fuori dall\'accordo, e il calendario non li conta; con la scelta il versamento va a U1', function () {
    [$s, $interessi, $sollecito, $quotaU1, $quotaU3] = vdaDueUnita($this);
    $this->travelTo('2026-07-20 10:00:00');
    expect((int) DB::table('rate_quote')->where('id', $quotaU3)->value('importo'))->toBe(7000);

    $senza = aclVersa($this, $s, [$quotaU3 => 7000], '2026-07-20', false);
    $su = fn (int $scrittura, int $quota) => (int) DB::table('righe_scritture')->where('scrittura_id', $scrittura)->where('tipo_riga', 'avere')
        ->where('immobile_id', DB::table('rate_quote')->where('id', $quota)->value('immobile_id'))->sum('importo');
    expect(ScritturaContabile::find($senza)->imputazione)->toBe(OrdineImputazione::ORDINE_SCADENZE)
        ->and([$su($senza, $quotaU1), $su($senza, $quotaU3)])->toBe([0, 7000])
        ->and(aclRate(aclLetto($this, $s))[0])->toBe([1, 0, 7531, false]);

    // Con la scelta, € 75,31 sulla riga della rata 1 (la quota di U3 come riga): interessi e sollecito, poi € 49,39 a U1, nell'accordo.
    $con = aclVersa($this, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, $quotaU3 => 4939], '2026-07-31');
    expect(ScritturaContabile::find($con)->imputazione)->toBe(OrdineImputazione::ACCORDO_RIENTRO)
        ->and([$su($con, $quotaU1), $su($con, $quotaU3)])->toBe([4939 + 92 + 2500, 0])
        ->and(aclRate(aclLetto($this, $s))[0])->toBe([1, 7531, 0, true]);
});

it('il versamento dell\'accordo su una rata coperta in parte va prima alla parte nell\'accordo: la rata del calendario è versata, nessun segnale; senza la scelta prima la parte fuori', function () {
    [$s, $interessi, $sollecito] = aclNeri($this);
    // La rata 1 ha € 200,00 di residuo; l'accordo ne riconosce € 150,00: € 175,92 in due rate da € 87,96, «15 giorni».
    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito, ['150,00', '0,92', '25,00']), aclCalendario(['87,96', '87,96'], ['2026-07-31', '2026-08-31']), '2026-07-15', null, 15)
        ->assertCreated();
    // Il 31/07 € 87,96 con la scelta: interessi 0,92, sollecito 25,00, rata 1 62,04 (87,96 − 25,92), tutti all'accordo.
    aclVersa($this, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 6204], '2026-07-31');
    $this->travelTo('2026-08-20 10:00:00');
    $letto = aclLetto($this, $s)['accordi'][0];
    expect(aclRate(['accordi' => [$letto]]))->toBe([[1, 8796, 0, true], [2, 0, 8796, false]])
        ->and($letto['segnali'])->toBe(['rate' => null, 'giorni' => null])
        // Nell'accordo restano 150,00 − 62,04 = 87,96 della rata 1; fuori, i € 50,00 non riconosciuti.
        ->and($letto['oggi'])->toBe(8796);
    $parti = app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->partiInCorso((int) $s['c']->id, [(int) $s['persone']['Neri']->id], '2026-08-20');
    expect(array_values($parti[(int) $s['persone']['Neri']->id]))->toBe([8796]);

    // Il 25/08 € 30,00 senza la scelta sulla rata 1: vanno alla parte fuori (€ 50,00), il calendario non cambia.
    aclVersa($this, $s, [lcdQuota($s, 'Neri', 1) => 3000], '2026-08-25', false);
    expect(aclRate(aclLetto($this, $s))[1])->toBe([2, 0, 8796, false]);
});
