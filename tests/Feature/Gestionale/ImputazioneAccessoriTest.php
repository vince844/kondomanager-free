<?php

/**
 * L'imputazione dell'art. 1194 c.c.: nell'ordine che il programma propone gli interessi e le spese addebitati alla persona vengono prima del capitale,
 * i più vecchi prima; poi le rate per scadenza. Chi sceglie altrimenti lo dichiara («Scelta da te»): il programma registra e segna, non rifiuta. La
 * ricevuta dice per ogni riga che cos'è.
 *
 * Il caso: Neri, rata 1 di € 300,00 scaduta il 31/03/2026 (`AccessoriScenario.php`); il 29/06 gli interessi di mora al 29/06 (€ 1,18) e le spese di
 * sollecito (€ 25,00, senza fattura, sulla voce «Spese postali»). Il 31/07 versa € 75,31: interessi € 1,18, sollecito € 25,00, rata 1 € 49,13.
 */

use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Neri con la rata 1 scaduta, gli interessi al 29/06 e il sollecito registrati il 29/06: le quote [interessi, sollecito, rata 1]. */
function iaaNeri($test): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-06-29 10:00:00');
    $interessi = accRegistra($test, $s, accProposta($test, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    $sollecito = adbRegistra($test, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'scadenza' => '2026-06-29',
        'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1', 'conto_id' => $s['postali']->id]])
        ->assertCreated()->json('rate.0');

    return [$s, (int) adbQuota($interessi)->id, (int) adbQuota($sollecito)->id, lcdQuota($s, 'Neri', 1)];
}

it('l\'ordine proposto mette prima gli interessi e le spese, poi la rata scaduta; un versamento che li segue è «secondo l\'ordine»', function () {
    [$s, $interessi, $sollecito, $rata1] = iaaNeri($this);
    $this->travelTo('2026-07-31 10:00:00');

    $righe = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [$s['c'], 'anagrafica_id' => $s['persone']['Neri']->id]))->assertOk()->json('rate');
    $aDebito = collect($righe)->filter(fn ($r) => $r['ordine'] !== null)->sortBy('ordine')->values();
    // Le prime tre: dopo vengono le rate 2–4, non ancora emesse.
    expect($aDebito->take(3)->map(fn ($r) => [$r['id'], $r['accessorio']])->all())->toBe([[$interessi, true], [$sollecito, true], [$rata1, false]])
        ->and($aDebito->slice(3)->pluck('accessorio')->unique()->all())->toBe([false]);

    // € 75,31: € 1,18 + € 25,00 + € 49,13 (7.531 − 118 − 2.500 = 4.913).
    $incasso = lcdIncassa($this, $s, 'Neri', [$interessi => 118, $sollecito => 2500, $rata1 => 4913], '2026-07-31');
    expect(ScritturaContabile::find($incasso)->imputazione)->toBe(OrdineImputazione::ORDINE_SCADENZE);

    // La ricevuta: riga per riga, con il nome di che cos'è, gli accessori per primi.
    $ricevuta = app(RicevutaIncasso::class)->per(ScritturaContabile::findOrFail($incasso));
    expect(array_map(fn ($r) => [$r['rata'], $r['imputato'], $r['dopo']], $ricevuta['quote']))->toBe([
        ['Interessi di mora al 29/06/2026', 118, 0],
        ['Spese di sollecito', 2500, 0],
        ['Rata 1', 4913, 30000 - 4913],
    ]);
});

it('il capitale prima degli accessori è fuori dall\'ordine: senza una scelta è «fuori ordine, non dichiarata», con «Scelta da te» si registra e si segna', function () {
    [$s, $interessi, $sollecito, $rata1] = iaaNeri($this);
    $this->travelTo('2026-07-31 10:00:00');

    $esito = $this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.ordine', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-07-31', 'importo_totale' => '50.00', 'eccedenza' => '0.00',
        'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $rata1, 'importo' => '50.00']],
    ])->assertOk()->json();
    expect($esito['esito'])->toBe(OrdineImputazione::FUORI_ORDINE)->and($esito['rata_aperta']['descrizione'])->toBe('Interessi di mora al 29/06/2026');

    $senza = lcdIncassa($this, $s, 'Neri', [$rata1 => 5000], '2026-07-31');
    expect(ScritturaContabile::find($senza)->imputazione)->toBe(OrdineImputazione::FUORI_ORDINE);

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-07-31', 'importo_totale' => '50.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $rata1, 'importo' => '50.00']],
        'imputazione_scelta' => OrdineImputazione::SCELTA_AMMINISTRATORE, 'imputazione_nota' => 'Il condòmino paga la rata con la causale',
    ])->assertSessionHasNoErrors();
    $scelta = ScritturaContabile::where('tipo_movimento', 'incasso_rata')->orderByDesc('id')->first();
    expect($scelta->imputazione)->toBe(OrdineImputazione::SCELTA_AMMINISTRATORE)->and($scelta->imputazione_nota)->toBe('Il condòmino paga la rata con la causale');
    // Gli accessori restano: niente di loro è stato toccato.
    expect((int) DB::table('rate_quote')->where('id', $interessi)->value('importo_pagato'))->toBe(0)
        ->and((int) DB::table('rate_quote')->where('id', $sollecito)->value('importo_pagato'))->toBe(0);
});

it('una spesa che scade più avanti sta fra le rate per la sua scadenza: la rata scaduta viene prima, e versarla è «secondo l\'ordine»', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-07-15 10:00:00');
    $spesa = adbQuota(adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'scadenza' => '2026-09-30',
        'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'nessuna', 'conto_id' => $s['postali']->id]])->assertCreated()->json('rate.0'))->id;
    $rata1 = lcdQuota($s, 'Neri', 1);

    $ordine = app(OrdineImputazione::class)->rateAperte((int) $s['c']->id, (int) $s['persone']['Neri']->id);
    // La rata 1 (31/03) prima; la spesa del 30/09 fra le rate, per la sua scadenza: a pari giorno prima della rata 3, che non è emessa.
    expect(array_map(fn ($r) => [$r['scadenza'], $r['accessorio']], $ordine))->toBe([
        ['2026-03-31', false], ['2026-06-30', false], ['2026-09-30', true], ['2026-09-30', false], ['2026-12-31', false],
    ]);
    $righe = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [$s['c'], 'anagrafica_id' => $s['persone']['Neri']->id]))->assertOk()->json('rate');
    $prima = collect($righe)->firstWhere('ordine', 1);
    $laSpesa = collect($righe)->firstWhere('id', $spesa);
    expect($prima['id'])->toBe($rata1)->and($laSpesa['accessorio'])->toBeTrue()->and($laSpesa['in_testa'])->toBeFalse();

    $incasso = lcdIncassa($this, $s, 'Neri', [$rata1 => 30000], '2026-07-15');
    expect(ScritturaContabile::find($incasso)->imputazione)->toBe(OrdineImputazione::ORDINE_SCADENZE);

    // Il giorno della scadenza la spesa passa davanti: l'ordine la mette prima della rata 2, scaduta.
    $this->travelTo('2026-09-30 10:00:00');
    $ordine = app(OrdineImputazione::class)->rateAperte((int) $s['c']->id, (int) $s['persone']['Neri']->id);
    expect($ordine[0]['accessorio'])->toBeTrue()->and($ordine[1]['scadenza'])->toBe('2026-06-30');

    // La ricevuta di un versamento del 20/07 che paga la rata 2 e la spesa: prima la rata 2, poi la spesa che non era ancora scaduta.
    $this->travelTo('2026-07-20 10:00:00');
    $insieme = lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 2) => 30000, $spesa => 2500], '2026-07-20');
    $ricevuta = app(RicevutaIncasso::class)->per(ScritturaContabile::findOrFail($insieme));
    expect(array_column($ricevuta['quote'], 'rata'))->toBe(['Rata 2', 'Spese di sollecito']);
});

it('la nota dice che gli interessi e le spese vengono prima solo se l\'esercizio della nota ne ha: gli interessi del 2026 non cambiano la nota del 2027', function () {
    [$s, $interessi, $sollecito, $rata1] = iaaNeri($this);
    $this->travelTo('2026-07-15 10:00:00');
    lcdIncassa($this, $s, 'Neri', [$interessi => 118], '2026-07-15');
    expect(\App\Services\Gestionale\FattiDellaNota::k4($s['e']->fresh())['frase'])->toStartWith(\App\Services\Gestionale\FattiDellaNota::K4_ACCESSORI);

    // Il 2027, con il 2026 chiuso: un versamento sulla rata 1, e nessun interesse o spesa addebitati nel 2027.
    [$e27] = adbAnnoDopo($s, 'chiuso');
    $this->travelTo('2027-01-20 10:00:00');
    lcdIncassa($this, $s, 'Bianchi', [lcdQuota($s, 'Bianchi', 1) => 18000], '2027-01-20');
    expect(\App\Services\Gestionale\FattiDellaNota::k4($e27->fresh())['frase'])->toStartWith(\App\Services\Gestionale\FattiDellaNota::K4)
        ->and(\App\Services\Gestionale\FattiDellaNota::K4_ACCESSORI)->toContain('prima agli interessi e alle spese addebitati già scaduti');
});
