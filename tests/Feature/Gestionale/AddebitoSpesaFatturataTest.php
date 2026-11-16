<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La spesa di una sola unità già fatturata, addebitata a una persona: il piano degli addebiti porta la riga del riparto «ad personam» con la riga di
 * fattura, l'emissione chiude l'anticipazione dell'unità invece di contare la spesa fra le quote, e il conguaglio la conta chiesta, una volta sola.
 *
 * Due casi su Via Verdi. (1) La spesa senza un titolare: Verdi esce il 30/11 e nessuno subentra; una fattura del 10/12 con la riga su U3, € 80,00,
 * resta «di giorni senza un titolare» e aperta sull'unità; addebitata a Verdi, non è più né senza titolare né aperta, e il controllo CA torna. (2) Il
 * sollecito: lo studio fattura al condominio € 25,00 con la riga su U1; prima dell'addebito è fra le spese personali di Neri non ancora chieste;
 * addebitato a Neri come spesa per il ritardo esce dalle non chieste ed entra nella posizione: il saldo di Neri resta € 425,00, non € 450,00.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaRiparto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AddebitiScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Le righe dell'emissione di un addebito: [ruolo, verso, importo, con persona?, unità, con la rata?]. */
function adbEmissioneDellaSpesa(int $rataId): array
{
    $sc = (int) RataQuote::where('rata_id', $rataId)->value('scrittura_contabile_id');

    return DB::table('righe_scritture as r')->join('conti_contabili as c', 'c.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $sc)->orderBy('r.id')
        ->get(['c.ruolo', 'r.tipo_riga', 'r.importo', 'r.anagrafica_id', 'r.immobile_id', 'r.rata_id'])
        ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo, $r->anagrafica_id !== null, (int) $r->immobile_id, (int) $r->rata_id === $rataId])->all();
}

it('la spesa di U3 senza titolare, addebitata a Verdi con la sua riga di fattura: l\'emissione chiude l\'anticipazione, la spesa non è più senza titolare, CA torna', function () {
    $s = cpcViaVerdi($this);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_fine' => '2026-11-30']);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U3', 8000, '2026-12-10');
    $u3 = (int) $s['unita']['U3']->id;

    $prima = cpcLeggi($this, $s);
    $verdiPrima = cpcDi($prima, $s, 'Verdi', 'U3');
    expect($prima['personali_per_unita'][$u3])->toBe(['non_chieste' => 0, 'senza_titolare' => 8000, 'non_emesse' => 0, 'aperte' => 8000]);

    $this->travelTo('2026-12-31 10:00:00');
    $rataId = adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '80,00', 'causale' => 'Spesa di una sola unità: Citofono, U3', 'riga_fattura_id' => $riga,
        'scadenza' => '2026-12-31']])->assertCreated()->json('rate.0');

    // La riga del riparto della spesa, nel piano degli addebiti; la quota è un contributo.
    expect(RigaRiparto::where('riga_fattura_id', $riga)->get(['tipo', 'anagrafica_id', 'immobile_id', 'importo'])->map(fn ($r) => [$r->tipo, (int) $r->anagrafica_id, (int) $r->immobile_id, (int) $r->importo])->all())
        ->toBe([['ad_personam', (int) $s['persone']['Verdi']->id, $u3, 8000]])
        ->and(RataQuote::where('rata_id', $rataId)->value('tipo'))->toBe('addebito')
        // L'emissione: il DARE a Verdi, e l'AVERE che chiude l'anticipazione di U3 senza persona; niente su «Gestione rate».
        ->and(adbEmissioneDellaSpesa($rataId))->toBe([
            ['crediti_condomini', 'dare', 8000, true, $u3, true],
            ['crediti_condomini', 'avere', 8000, false, $u3, true],
        ]);

    $dopo = cpcLeggi($this, $s);
    $verdiDopo = cpcDi($dopo, $s, 'Verdi', 'U3');
    expect($dopo['personali_per_unita'][$u3] ?? null)->toBeNull()
        ->and($verdiDopo['conguaglio'])->toBe($verdiPrima['conguaglio'])
        ->and($verdiDopo['posizione'] - $verdiPrima['posizione'])->toBe(8000)
        ->and(\App\Services\Gestionale\ControlliDelConguaglio::personali($dopo)['esito'])->toBe('quadra');

    // Chiesta due volte no: la stessa riga non si addebita di nuovo.
    adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '1,00', 'riga_fattura_id' => $riga]])->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'Di questa spesa restano da chiedere € 0,00: il resto è già chiesto con le rate o con un altro addebito.');

    // L'annullamento la rimette com'era: senza titolare e aperta.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rataId]))->assertOk();
    expect(RigaRiparto::where('riga_fattura_id', $riga)->exists())->toBeFalse()
        ->and(cpcLeggi($this, $s)['personali_per_unita'][$u3])->toBe(['non_chieste' => 0, 'senza_titolare' => 8000, 'non_emesse' => 0, 'aperte' => 8000]);
});

it('la quota non assegnata «spesa di una sola unità senza titolare» del piano: la finestra la propone con la sua riga, e l\'addebito porta la riga del riparto con quella riga di fattura', function () {
    $s = cpcViaVerdi($this);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_fine' => '2026-11-30']);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    // La fattura con due righe uguali su U3 (la seconda è quella senza titolare) e un'altra riga: il piano la porta con sé, e la sua quota non
    // assegnata ha la chiave che la generazione scrive per una spesa di una sola unità senza titolare (l'impronta della descrizione, e «#2» per la
    // seconda riga uguale).
    $prima = cpcSpesaPersonale($s, 'U3', 8000, '2026-12-10');
    $fattura = (int) DB::table('righe_fattura')->where('id', $prima)->value('fattura_passiva_id');
    $seconda = DB::table('righe_fattura')->insertGetId(['fattura_passiva_id' => $fattura, 'conto_id' => null, 'immobile_id' => $s['unita']['U3']->id, 'descrizione' => 'Citofono',
        'importo_imponibile' => 3000, 'aliquota_iva' => 0, 'importo_iva' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura, 'conto_id' => null, 'immobile_id' => $s['unita']['U3']->id, 'descrizione' => 'Serratura',
        'importo_imponibile' => 1000, 'aliquota_iva' => 0, 'importo_iva' => 0, 'created_at' => now(), 'updated_at' => now()]);
    // Il giornale della fattura con le due righe in più: l'anticipazione di U3 sale di € 40,00.
    $scrittura = (int) DB::table('fattura_scrittura')->where('fattura_passiva_id', $fattura)->value('scrittura_contabile_id');
    DB::table('righe_scritture')->where('scrittura_id', $scrittura)->increment('importo', 4000);
    DB::table('fatture_passive')->where('id', $fattura)->update(['importo_imponibile' => 12000, 'totale_documento' => 12000, 'netto_a_pagare' => 12000]);
    DB::table('piano_rate_fatture')->insert(['piano_rate_id' => $s['piano']->id, 'fattura_passiva_id' => $fattura, 'importo_collegato' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $u3 = (int) $s['unita']['U3']->id;
    $chiave = 'ad_personam_senza_titolare|' . $u3 . '||||' . substr(md5('Citofono'), 0, 10) . '#2';
    $s['piano']->forceFill(['quote_non_assegnate' => ['voci' => [['chiave' => $chiave, 'motivo' => 'ad_personam_senza_titolare', 'immobile_id' => $u3, 'immobile_nome' => 'U3',
        'conto_id' => null, 'conto_nome' => null, 'tabella_id' => null, 'tabella_nome' => null, 'ruolo_richiesto' => null, 'importo_cents' => 3000, 'giorni' => null,
        'riga_descrizione' => 'Citofono', 'segno' => 1, 'accettata' => true]], 'scarto_cents' => 3000, 'registrate_il' => now()->toIso8601String(), 'registrate_da' => null]])->save();
    $this->travelTo('2026-12-31 10:00:00');

    $p = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?' . http_build_query(['piano_rate_id' => $s['piano']->id, 'chiavi' => [$chiave]]))
        ->assertOk()->json();
    expect($p['voci'][0]['causale'])->toBe('Spesa di una sola unità: Citofono, Int. U3')
        ->and($p['voci'][0]['spesa_fatturata'])->toBeTrue()
        ->and($p['persone'][0]['nome'])->toBe('Verdi');

    adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '30,00', 'causale' => $p['voci'][0]['causale'], 'chiave' => $chiave, 'piano_origine_id' => $s['piano']->id,
        'scadenza' => '2026-12-31']])->assertCreated();
    expect(RigaRiparto::where('tipo', 'ad_personam')->where('piano_rate_id', '!=', $s['piano']->id)->pluck('riga_fattura_id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $seconda])
        // Chiesta la seconda riga, restano senza titolare e aperte la prima (€ 80,00) e la «Serratura» (€ 10,00).
        ->and(cpcLeggi($this, $s)['personali_per_unita'][$u3])->toBe(['non_chieste' => 0, 'senza_titolare' => 9000, 'non_emesse' => 0, 'aperte' => 9000]);
});

it('il sollecito di € 25,00 fatturato con la riga su U1 e addebitato a Neri esce dalle personali non chieste: il saldo di Neri resta € 425,00 e CA torna', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U1', 2500, '2026-06-01');
    $prima = cpcDi(cpcLeggi($this, $s), $s, 'Neri', 'U1');
    expect([$prima['personali']['non_chieste'], $prima['saldo']])->toBe([2500, 42500]);

    $this->travelTo('2026-06-10 10:00:00');
    $rataId = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'natura' => 'accessorio',
        'riga_fattura_id' => $riga, 'scadenza' => '2026-06-10']])->assertCreated()->json('rate.0');
    expect(RataQuote::where('rata_id', $rataId)->value('tipo'))->toBe('accessorio');

    $letto = cpcLeggi($this, $s);
    $dopo = cpcDi($letto, $s, 'Neri', 'U1');
    expect($dopo['personali']['non_chieste'])->toBe(0)
        ->and($dopo['b']['totale'])->toBe($prima['b']['totale'])
        ->and($dopo['posizione'] - $prima['posizione'])->toBe(2500)
        ->and($dopo['saldo'])->toBe(42500)
        ->and($dopo['personali_non_tornano'])->toBeNull()
        ->and(\App\Services\Gestionale\ControlliDelConguaglio::personali($letto)['esito'])->toBe('quadra');

    // Una spesa per il ritardo senza la sua fattura chiede che cos'è, la base e la voce che riceve il denaro: senza, si rifiuta.
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '5,00', 'natura' => 'accessorio']])->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'Scegli che cos\'è la spesa: interessi di mora, spese di sollecito, spese legali o altro.');
});

it('il sollecito fatturato il 10/12/2026 e addebitato il 10/02/2027, con il 2026 ancora aperto, va nel piano degli addebiti del 2026: il conguaglio del 2026 lo conta chiesto, e Neri ha € 425,00 una volta sola', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U1', 2500, '2026-12-10');
    $prima = cpcDi(cpcLeggi($this, $s), $s, 'Neri', 'U1');
    expect([$prima['personali']['non_chieste'], $prima['saldo']])->toBe([2500, 42500]);
    [$e27, $g27] = adbAnnoDopo($s);

    // La finestra dall'estratto conto: la spesa porta il suo posto, la gestione e l'esercizio della fattura, anche se la proposta è il 2027.
    $this->travelTo('2027-02-10 10:00:00');
    $p = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?anagrafica_id=' . $s['persone']['Neri']->id)->assertOk()->json();
    expect($p['dove']['proposta']['nome'])->toBe('Ordinaria 2027 (Esercizio 2027)')
        ->and(collect($p['spese'])->firstWhere('riga_fattura_id', $riga)['dove'])->toBe(['gestione_id' => (int) $s['g']->id, 'esercizio_id' => (int) $s['e']->id,
            'nome' => 'Ordinaria 2026 (Esercizio 2026)']);

    // Nel 2027 si rifiuta: il conguaglio del 2026 conta chiesto solo dai piani fino al 2026.
    $numero = (string) DB::table('righe_fattura as rf')->join('fatture_passive as fp', 'fp.id', '=', 'rf.fattura_passiva_id')->where('rf.id', $riga)->value('fp.numero_documento');
    $voce = ['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'natura' => 'accessorio', 'riga_fattura_id' => $riga, 'scadenza' => '2027-02-25'];
    adbRegistra($this, $s, [$voce], $g27->id, $e27->id)->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', "La fattura n. {$numero} è dell'esercizio «Esercizio 2026», gestione «Ordinaria 2026»: l'addebito della sua spesa si scrive lì, "
            . 'così il conguaglio di quell\'esercizio la conta chiesta, una volta sola.');
    $rataId = adbRegistra($this, $s, [$voce])->assertCreated()->json('rate.0');

    // Nel piano degli addebiti del 2026, con la data della fine del 2026 (oggi è dopo): la riga del riparto sta lì.
    $piano = \App\Models\Gestionale\PianoRate::degliAddebiti()->sole();
    $scrittura = DB::table('scritture_contabili')->where('id', RataQuote::where('rata_id', $rataId)->value('scrittura_contabile_id'))->first();
    expect([(int) $piano->esercizio_id, (int) $piano->gestione_id])->toBe([(int) $s['e']->id, (int) $s['g']->id])
        ->and(RigaRiparto::where('riga_fattura_id', $riga)->pluck('piano_rate_id')->all())->toBe([(int) $piano->id])
        ->and([(int) $scrittura->esercizio_id, substr((string) $scrittura->data_competenza, 0, 10)])->toBe([(int) $s['e']->id, '2026-12-31']);

    // Il conguaglio del 2026, letto il 01/03/2027: le personali non chieste sono zero, il sollecito è nella posizione, il saldo resta € 425,00.
    $this->travelTo('2027-03-01 10:00:00');
    $dopo = cpcDi((new \App\Services\Gestionale\ConsuntivoPerPersona())->perEsercizio($s['e']->fresh()), $s, 'Neri', 'U1');
    expect($dopo['personali']['non_chieste'])->toBe(0)
        ->and($dopo['posizione'] - $prima['posizione'])->toBe(2500)
        ->and($dopo['saldo'])->toBe(42500);
    // Fra le spese da chiedere della finestra non c'è più.
    $p = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?anagrafica_id=' . $s['persone']['Neri']->id)->assertOk()->json();
    expect(collect($p['spese'])->pluck('riga_fattura_id')->all())->not->toContain($riga);
});

it('con il 2026 chiuso il sollecito fatturato nel 2026 non si addebita nel 2027: il suo conguaglio lo chiede già; la finestra non lo propone', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U1', 2500, '2026-12-10');
    [$e27, $g27] = adbAnnoDopo($s, 'chiuso');

    $this->travelTo('2027-02-10 10:00:00');
    $p = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?anagrafica_id=' . $s['persone']['Neri']->id)->assertOk()->json();
    expect(collect($p['spese'])->pluck('riga_fattura_id')->all())->not->toContain($riga);
    $numero = (string) DB::table('righe_fattura as rf')->join('fatture_passive as fp', 'fp.id', '=', 'rf.fattura_passiva_id')->where('rf.id', $riga)->value('fp.numero_documento');
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'natura' => 'accessorio', 'riga_fattura_id' => $riga,
        'scadenza' => '2027-02-25']], $g27->id, $e27->id)->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', "La fattura n. {$numero} è dell'esercizio «Esercizio 2026», chiuso: la spesa è fra le spese personali del suo conguaglio, che la chiede già.");
    expect(RigaRiparto::where('riga_fattura_id', $riga)->count())->toBe(0)
        ->and(\App\Models\Gestionale\PianoRate::degliAddebiti()->count())->toBe(0);
});
