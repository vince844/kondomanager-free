<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il contributo addebitato nel rendiconto: fuori dalle quote del conguaglio (B), dentro la posizione; detto accanto alle spese non attribuite,
 * «addebitati per questa voce», con quello che resta da addebitare dalla stessa scheda; fuori dal deliberato, con una riga sua nel blocco delle quote
 * e nel raccordo, così le quote a giornale tornano come quote dei piani più contributi addebitati. La data dell'addebito sta dentro il suo esercizio;
 * le spese di una sola unità chieste a una persona hanno la loro riga, e «Contributi addebitati» ha una cifra sola.
 *
 * Il caso: Via Verdi con U3 senza titolare per 31 giorni (Verdi, 200 millesimi, esce il 30/11 e nessuno subentra). Nel preventivo i 31 giorni di
 * dicembre restano fuori dalle rate: Pulizie € 3.000,00 × 0,2 × 31/365 = 50,9589 → € 50,96 e Manutenzioni € 1.000,00 × 0,2 × 31/365 = 16,9863 → € 16,99, in tutto € 67,95,
 * addebitati a Verdi. Nel consuntivo gli stessi giorni sono Pulizie € 3.300,00 × 0,2 × 31/365 = 56,0548 → € 56,05 e Manutenzioni € 900,00 × 0,2 ×
 * 31/365 = 15,2877 → € 15,29: € 71,34 non attribuiti, € 67,95 addebitati; restano € 3,39.
 */

use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AddebitiScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Le colonne del conguaglio di una persona su un'unità: A, personali, B, conguaglio, posizione, saldo. */
function adbColonneDelConguaglio($test, array $s, string $chi, string $unita, string $oggi = '2026-12-31 12:00:00'): array
{
    $r = cpcDi(cpcLeggi($test, $s, $oggi), $s, $chi, $unita);

    return ['a' => $r['a'], 'personali' => $r['personali']['non_chieste'], 'b' => $r['b']['totale'], 'conguaglio' => $r['conguaglio'], 'posizione' => $r['posizione'], 'saldo' => $r['saldo']];
}

/** Addebita a Verdi le due quote non assegnate di U3 dal piano, come fa il riquadro del piano. */
function adbAddebitaAVerdi($test, array $s, ?int $gestioneId = null, ?int $esercizioId = null)
{
    $quote = adbQuoteNonAssegnate($s);

    return adbRegistra($test, $s, array_map(fn (string $voce) => ['chi' => 'Verdi', 'unita' => 'U3', 'euro' => number_format($quote[$voce]['importo_cents'] / 100, 2, ',', ''),
        'causale' => "Quota non assegnata: {$voce}, U3, 31 giorni senza titolare", 'chiave' => $quote[$voce]['chiave'], 'piano_origine_id' => $s['piano']->id],
        ['Pulizie', 'Manutenzioni']), $gestioneId, $esercizioId);
}

it('le quote non assegnate di U3 sono € 50,96 e € 16,99; addebitate a Verdi non cambiano il suo conguaglio, entrano nella posizione, e la scheda dice quanto è addebitato per voce e i € 3,39 che restano', function () {
    $s = adbVerdiFinoANovembre($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $quote = adbQuoteNonAssegnate($s);
    expect([$quote['Pulizie']['importo_cents'], $quote['Manutenzioni']['importo_cents']])->toBe([5096, 1699])
        ->and([$quote['Pulizie']['motivo'], $quote['Pulizie']['immobile_id']])->toBe(['giorni_senza_titolare', (int) $s['unita']['U3']->id]);
    $prima = adbColonneDelConguaglio($this, $s, 'Verdi', 'U3');

    $this->travelTo('2026-11-02 10:00:00');
    adbAddebitaAVerdi($this, $s)->assertCreated();
    $dopo = adbColonneDelConguaglio($this, $s, 'Verdi', 'U3');

    // B senza l'addebito: il conguaglio di Verdi non cambia; l'addebito è nella posizione e nel saldo.
    expect($dopo['a'])->toBe($prima['a'])
        ->and($dopo['b'])->toBe($prima['b'])
        ->and($dopo['conguaglio'])->toBe($prima['conguaglio'])
        ->and($dopo['posizione'] - $prima['posizione'])->toBe(6795)
        ->and($dopo['saldo'] - $prima['saldo'])->toBe(6795);

    // La scheda: le spese non attribuite per voce con quanto è addebitato per lei, e per la gestione quanto resta da addebitare.
    $scheda = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];
    $g = collect($scheda['gestioni'])->firstWhere('id', $s['g']->id);
    $perVoce = collect($g['non_attribuite'])->mapWithKeys(fn ($n) => [$n['voce'] => [$n['importo'], $n['coperti']]])->all();
    expect($perVoce)->toBe(['Pulizie' => [5605, 5096], 'Manutenzioni' => [1529, 1699]])
        ->and($g['addebiti'])->toBe([
            'coperti' => 6795, 'non_attribuite' => 7134, 'restano' => 339,
            'per_persona' => [['anagrafica_id' => (int) $s['persone']['Verdi']->id, 'nome' => 'Verdi', 'unita' => 'Int. U3', 'importo' => 6795]],
            'frase' => 'Addebitati per queste spese: € 67,95 a Verdi (Int. U3). Restano € 3,39 delle spese non attribuite di giorni senza titolare.',
        ]);

    // I € 3,39 dalla scheda: l'addebito porta la gestione e l'esercizio di origine, e dopo non resta niente.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.store', [$s['c']]), ['gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id,
        'voci' => [['anagrafica_id' => $s['persone']['Verdi']->id, 'immobile_id' => $s['unita']['U3']->id, 'importo' => '3,39', 'causale' => 'Spese dei giorni senza titolare, Ordinaria 2026',
            'scadenza' => '2026-11-02', 'gestione_origine_id' => $s['g']->id, 'esercizio_origine_id' => $s['e']->id]]])->assertCreated();
    $scheda = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];
    $g = collect($scheda['gestioni'])->firstWhere('id', $s['g']->id);
    expect([$g['addebiti']['coperti'], $g['addebiti']['restano']])->toBe([7134, 0])
        ->and(adbColonneDelConguaglio($this, $s, 'Verdi', 'U3')['conguaglio'])->toBe($prima['conguaglio']);
});

it('una quota del 2026 addebitata nel 2027, con il 2026 chiuso, sta nel piano degli addebiti del 2027 e gli addebitati del 2026 la vedono', function () {
    $s = adbVerdiFinoANovembre($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $prima = adbColonneDelConguaglio($this, $s, 'Verdi', 'U3');
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $g2027 = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2027', 'tipo' => 'ordinaria', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    legaAEsercizio($e2027, $g2027->id);
    $s['e']->update(['stato' => 'chiuso']);

    // Nell'esercizio chiuso non si scrive.
    $this->travelTo('2027-03-10 10:00:00');
    adbAddebitaAVerdi($this, $s)->assertStatus(422);
    // La finestra propone l'esercizio aperto di oggi, con la gestione dello stesso tipo.
    $proposta = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?' . http_build_query(['piano_rate_id' => $s['piano']->id,
        'chiavi' => array_column(adbQuoteNonAssegnate($s), 'chiave')]))->assertOk()->json();
    expect($proposta['dove']['proposta'])->toBe(['gestione_id' => (int) $g2027->id, 'esercizio_id' => (int) $e2027->id, 'nome' => 'Ordinaria 2027 (Esercizio 2027)',
        'inizio' => '2027-01-01', 'fine' => '2027-12-31']);

    adbAddebitaAVerdi($this, $s, $g2027->id, $e2027->id)->assertCreated();
    $piano = PianoRate::degliAddebiti()->firstOrFail();
    expect([(int) $piano->gestione_id, (int) $piano->esercizio_id, $piano->nome])->toBe([(int) $g2027->id, (int) $e2027->id, 'Addebiti individuali – Ordinaria 2027']);

    // Il conguaglio del 2026 non cambia (l'addebito è di un piano del 2027); gli addebitati del 2026 lo vedono.
    $scheda = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];
    $g = collect($scheda['gestioni'])->firstWhere('id', $s['g']->id);
    expect([$g['addebiti']['coperti'], $g['addebiti']['restano']])->toBe([6795, 339])
        ->and(adbColonneDelConguaglio($this, $s, 'Verdi', 'U3', '2027-03-10 12:00:00')['conguaglio'])->toBe($prima['conguaglio']);
});

it('il deliberato non cambia con un contributo addebitato; il blocco delle quote ha la riga «addebiti» e il raccordo la sua: quote a giornale = quote dei piani + contributi', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $this->travelTo('2026-11-02 10:00:00');
    $prima = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh());
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Spesa della cantina', 'scadenza' => '2026-11-02']])->assertCreated();
    $dopo = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh());

    $blocco = fn (array $r) => collect($r['blocchi'])->firstWhere('gestione.id', $s['g']->id);
    expect($blocco($dopo)['deliberato_stato'])->toBe($blocco($prima)['deliberato_stato'])
        ->and($blocco($dopo)['deliberato_stato'])->toBe('conservato')
        ->and($blocco($dopo)['totale_spese']['deliberato'])->toBe($blocco($prima)['totale_spese']['deliberato'])
        ->and($dopo['quote']['totale']['deliberate'])->toBe(400000)
        // Il blocco delle quote: le ordinarie come prima, e la riga dei contributi addebitati, senza deliberato.
        ->and(collect($blocco($dopo)['quote'])->pluck('riga')->all())->toBe(['ordinarie', 'addebiti'])
        ->and(collect($blocco($dopo)['quote'])->firstWhere('riga', 'ordinarie'))->toBe(collect($blocco($prima)['quote'])->firstWhere('riga', 'ordinarie'))
        ->and(array_intersect_key(collect($blocco($dopo)['quote'])->firstWhere('riga', 'addebiti'), array_flip(['deliberate', 'emesse', 'non_pagate'])))
        ->toBe(['deliberate' => null, 'emesse' => 5000, 'non_pagate' => 5000])
        // Le quote dei piani restano quelle dei piani; le quote a giornale le comprendono con i contributi.
        ->and($dopo['quote']['totale']['emesse'])->toBe($prima['quote']['totale']['emesse'])
        ->and($dopo['quote']['totale']['addebiti']['emesse'])->toBe(5000)
        ->and($dopo['risultato']['quote_emesse'] - $prima['risultato']['quote_emesse'])->toBe(5000)
        // Le quote a giornale = il deliberato (€ 4.000,00, tutte le rate emesse) + i contributi addebitati; il raccordo ha la riga sua e le altre
        // differenze restano quelle di prima (il credito di Verdi dell'anno prima, dentro la sua prima rata).
        ->and($dopo['risultato']['quote_emesse'])->toBe(400000 + 5000)
        ->and($dopo['raccordo_quote'])->toBe(array_merge($prima['raccordo_quote'], ['contributi_addebitati' => 5000, 'contributi_non_versati' => 5000, 'quote_giornale' => $prima['raccordo_quote']['quote_giornale'] + 5000]))
        ->and($prima['raccordo_quote']['contributi_addebitati'])->toBe(0);
});

/** Il controllo AC del rendiconto dell'esercizio, con il conguaglio: l'esito e quante registrazioni oltre il confine. */
function adbControlloAC(array $s): array
{
    $controlli = app(\App\Services\Gestionale\ControlliDelRendiconto::class);
    $c = $controlli->conIlConguaglio($controlli->perEsercizio($s['c'], $s['e']->fresh()), app(\App\Services\Gestionale\ConguaglioDelRendiconto::class)->leggi($s['e']->fresh(), [], true));
    $ac = collect($c['controlli'] ?? $c)->first(fn ($x) => is_array($x) && ($x['id'] ?? null) === 'AC');

    return [$ac['esito'], $ac['sinistra']];
}

it('dalla scheda «Conguaglio», il 20/03/2027 con il 2026 ancora aperto, l\'addebito ha la data della fine del 2026: il controllo AC resta «quadra», e il riepilogo, la Sintesi e il frontespizio dicono la stessa cifra', function () {
    $s = adbVerdiFinoANovembre($this);
    cpcRateEIncassi($this, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpcSpese($s);
    $this->travelTo(\Carbon\Carbon::parse('2027-03-20 10:00', 'Europe/Rome'));
    expect(adbControlloAC($s))->toBe(['quadra', 0]);

    // La finestra propone la data della fine del 2026; fuori dal 2026 la registrazione si rifiuta.
    $p = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?gestione_id=' . $s['g']->id . '&esercizio_id=' . $s['e']->id)->assertOk()->json();
    expect([$p['oggi'], $p['dove']['proposta']['inizio'], $p['dove']['proposta']['fine']])->toBe(['2027-03-20', '2026-01-01', '2026-12-31']);
    $voce = ['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '3,39', 'causale' => 'Spese dei giorni senza titolare, Ordinaria 2026', 'scadenza' => '2027-04-10',
        'gestione_origine_id' => $s['g']->id, 'esercizio_origine_id' => $s['e']->id];
    adbRegistra($this, $s, [$voce], null, null, '2027-03-20')->assertStatus(422)
        ->assertJsonPath('errors.data.0', 'La data dell\'addebito va dentro l\'esercizio «Esercizio 2026», dal 01/01/2026 al 31/12/2026: è la data della sua scrittura.');
    $rataId = adbRegistra($this, $s, [$voce])->assertCreated()->json('rate.0');

    $scrittura = DB::table('scritture_contabili')->where('id', adbQuota($rataId)->scrittura_contabile_id)->first();
    expect([(int) $scrittura->esercizio_id, substr((string) $scrittura->data_competenza, 0, 10), substr((string) DB::table('rate')->where('id', $rataId)->value('data_emissione'), 0, 10)])
        ->toBe([(int) $s['e']->id, '2026-12-31', '2026-12-31'])
        ->and(adbControlloAC($s))->toBe(['quadra', 0]);

    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh());
    $riga = collect(collect($r['blocchi'])->firstWhere('gestione.id', $s['g']->id)['quote'])->firstWhere('riga', 'addebiti');
    $sintesi = app(\App\Services\Gestionale\SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh());
    expect([$riga['emesse'] ?? null, $r['raccordo_quote']['contributi_addebitati'], $sintesi['prospetto']['contributi_addebitati']])->toBe([339, 339, 339]);
});

it('un contributo di € 50,00 e un sollecito di € 25,00 con la sua fattura: «Contributi addebitati a singole persone» dice € 50,00 nel blocco delle quote, nel raccordo e nel frontespizio; il sollecito ha la riga sua', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U1', 2500, '2026-10-20');
    $this->travelTo('2026-11-02 10:00:00');
    $prima = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh());
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Contributo cantina', 'scadenza' => '2026-11-20'],
        ['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'natura' => 'accessorio', 'riga_fattura_id' => $riga, 'scadenza' => '2026-11-20']])
        ->assertCreated();
    $dopo = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh());
    $quote = collect(collect($dopo['blocchi'])->firstWhere('gestione.id', $s['g']->id)['quote'])->keyBy('riga');
    $sintesi = app(\App\Services\Gestionale\SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh());

    expect($quote->keys()->all())->toBe(['ordinarie', 'addebiti', 'spese_addebitate'])
        ->and([$quote['addebiti']['emesse'], $quote['spese_addebitate']['emesse'], $quote['spese_addebitate']['deliberate']])->toBe([5000, 2500, null])
        ->and([$dopo['quote']['totale']['addebiti']['emesse'], $dopo['quote']['totale']['spese_addebitate']['emesse']])->toBe([5000, 2500])
        ->and([$dopo['raccordo_quote']['contributi_addebitati'], $sintesi['prospetto']['contributi_addebitati']])->toBe([5000, 5000])
        // Le quote dei piani del riparto non cambiano; le quote a giornale crescono dei soli contributi, e il raccordo torna come prima.
        ->and($dopo['quote']['totale']['emesse'])->toBe($prima['quote']['totale']['emesse'])
        ->and($dopo['risultato']['quote_emesse'] - $prima['risultato']['quote_emesse'])->toBe(5000)
        ->and($dopo['raccordo_quote']['altre_differenze'])->toBe($prima['raccordo_quote']['altre_differenze']);
});
