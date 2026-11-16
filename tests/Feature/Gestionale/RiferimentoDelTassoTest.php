<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il riferimento di un tasso scritto: obbligatorio quando la regola nuova ha «Un tasso scritto, con la sua fonte», al più 150 caratteri, e stampato per intero
 * accanto al tasso nella causale degli interessi registrati, che l'estratto conto e la ricevuta leggono. Le regole già scritte senza riferimento restano:
 * dicono la fonte, come prima.
 *
 * Via Verdi (`AccessoriScenario.php`): Neri, € 300,00 della rata 1 scaduta il 31/03/2026. Il tasso legale del 2026 è 1,60 %; i giorni vanno dal giorno dopo
 * la scadenza alla data della proposta compresa, su 365. Ogni cifra è rifatta a mano accanto al suo caso.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegolaRecupero;
use App\Services\Gestionale\Accessori\RegoleDelRecupero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Un riferimento lungo 150 caratteri, il massimo. */
const RDT_LUNGO = 'assemblea straordinaria del 10/03/2025, verbale n. 13, punto 2 dell\'ordine del giorno, approvato all\'unanimità dei condòmini presenti e rappresentati.';
const RDT_ALTRO_LUNGO = 'art. 12 del regolamento contrattuale, allegato al primo atto di vendita del 12/05/1987, trascritto alla Conservatoria di Milano, reg. part. n. 4567890';

/** Una regola nuova dalla rotta della pagina, in JSON: la risposta. */
function rdtRegola($test, array $s, array $dati)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.recupero-crediti.store', [$s['c']]), $dati);
}

/** La causale della rata degli interessi registrati e quella della sua scrittura di emissione. */
function rdtCausali(int $rataId): array
{
    $scrittura = (int) RataQuote::where('rata_id', $rataId)->value('scrittura_contabile_id');

    return [(string) DB::table('rate')->where('id', $rataId)->value('descrizione'), (string) DB::table('scritture_contabili')->where('id', $scrittura)->value('causale')];
}

it('con un tasso scritto il riferimento è obbligatorio: senza, o con soli spazi, 422 e la frase; oltre 150 caratteri no; con il tasso legale non serve', function () {
    $s = accViaVerdi($this);
    $scritto = ['dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'regolamento'];

    rdtRegola($this, $s, $scritto)->assertStatus(422)
        ->assertJsonValidationErrors(['riferimento' => 'Scrivi il riferimento del tasso: da dove viene, così si può controllare.']);
    rdtRegola($this, $s, $scritto + ['riferimento' => '   '])->assertStatus(422)->assertJsonValidationErrors(['riferimento']);
    rdtRegola($this, $s, $scritto + ['riferimento' => RDT_LUNGO . 'x'])->assertStatus(422)
        ->assertJsonValidationErrors(['riferimento' => 'Il riferimento sta in 150 caratteri: si stampa per intero accanto al tasso.']);
    expect(RegolaRecupero::count())->toBe(0);

    // 150 caratteri sono il massimo: la regola si registra, con il riferimento intero.
    expect(mb_strlen(RDT_LUNGO))->toBe(150);
    rdtRegola($this, $s, $scritto + ['riferimento' => RDT_LUNGO])->assertRedirect();
    expect(RegolaRecupero::sole()->riferimento)->toBe(RDT_LUNGO);

    // Il tasso legale dell'anno non chiede il riferimento.
    rdtRegola($this, $s, ['dal' => '2026-09-01', 'tasso_tipo' => 'legale'])->assertRedirect();
    expect(RegolaRecupero::count())->toBe(2);
});

it('una regola già scritta senza riferimento resta: la pagina la manda senza riferimento, la lettera e la causale dicono la fonte', function () {
    $s = accViaVerdi($this);
    RegolaRecupero::create(['condominio_id' => $s['c']->id, 'dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3.0000', 'fonte' => 'regolamento',
        'riferimento' => null, 'giorni_decorrenza' => 0]);
    $this->travelTo('2026-06-29 10:00:00');

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.recupero-crediti.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect([$props['regole'][0]['tasso_tipo'], $props['regole'][0]['riferimento'], $props['regole'][0]['tasso']])->toBe(['scritto', null, '3,00 % annuo, regolamento'])
        ->and($props['tasso_di_oggi'])->toBe('3,00 % annuo, regolamento');

    // 300,00 × 3,00 % × 90/365 = 2,219178 → € 2,22.
    $proposta = accProposta($this, $s, ['Neri'])->json();
    expect($proposta['totale'])->toBe(222);
    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    expect(rdtCausali($rataId))->toBe(['Interessi di mora al 29/06/2026 (3,00 % annuo, regolamento)', 'Addebito: Interessi di mora al 29/06/2026 (3,00 % annuo, regolamento)']);
});

it('gli interessi con un tasso scritto: la causale dice il tasso e il riferimento per intero, nella rata, nella scrittura, nell\'estratto conto', function () {
    $s = accViaVerdi($this);
    rdtRegola($this, $s, ['dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '5', 'fonte' => 'regolamento', 'riferimento' => RDT_ALTRO_LUNGO])->assertRedirect();
    $this->travelTo('2026-06-29 10:00:00');

    // 300,00 × 5,00 % × 90/365 = 3,698630 → € 3,70.
    $proposta = accProposta($this, $s, ['Neri'])->json();
    expect($proposta['totale'])->toBe(370);
    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    $causale = 'Interessi di mora al 29/06/2026 (5,00 % annuo, ' . RDT_ALTRO_LUNGO . ')';
    expect(rdtCausali($rataId))->toBe([$causale, 'Addebito: ' . $causale])
        ->and(mb_strlen('Addebito: ' . $causale))->toBeLessThanOrEqual(255);

    // L'estratto conto della persona: la riga dell'emissione e l'elenco degli addebiti.
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['Neri']]))->assertOk()->viewData('page')['props'];
    $righe = collect($props['timeline'])->pluck('descrizione')->all();
    expect($righe)->toContain('Addebito: ' . $causale)
        ->and(collect($props['addebiti'])->pluck('causale')->all())->toContain($causale);
});

it('con giorni al tasso legale e poi a un tasso scritto la causale li dice in ordine: «tasso legale; poi 3,00 % annuo, …»', function () {
    $s = accViaVerdi($this);
    rdtRegola($this, $s, ['dal' => '2026-07-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento contrattuale'])
        ->assertRedirect();
    $this->travelTo('2026-07-31 10:00:00');

    // 300,00 × 1,60 % × 91/365 = 1,196712 + 300,00 × 3,00 % × 31/365 = 0,764384 = 1,961096 → € 1,96.
    $proposta = accProposta($this, $s, ['Neri'])->json();
    expect($proposta['totale'])->toBe(196);
    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    expect(rdtCausali($rataId)[0])->toBe('Interessi di mora al 31/07/2026 (tasso legale; poi 3,00 % annuo, art. 12 del regolamento contrattuale)');
});

it('con il solo tasso legale la causale resta «Interessi di mora al …»', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    $rataId = accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    expect(rdtCausali($rataId))->toBe(['Interessi di mora al 29/06/2026', 'Addebito: Interessi di mora al 29/06/2026']);
});

it('due tassi scritti con due riferimenti corti: la causale li dice tutti e due, in ordine', function () {
    $s = accViaVerdi($this);
    rdtRegola($this, $s, ['dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento contrattuale'])
        ->assertRedirect();
    rdtRegola($this, $s, ['dal' => '2026-05-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '4', 'fonte' => 'delibera',
        'riferimento' => 'assemblea del 10/03/2025, verbale n. 3, all\'unanimità'])->assertRedirect();
    $this->travelTo('2026-06-29 10:00:00');

    // 300,00 × 3,00 % × 30/365 = 0,739726 + 300,00 × 4,00 % × 60/365 = 1,972603 = 2,712329 → € 2,71.
    $proposta = accProposta($this, $s, ['Neri'])->json();
    expect($proposta['totale'])->toBe(271);
    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    expect(rdtCausali($rataId)[0])->toBe('Interessi di mora al 29/06/2026 (3,00 % annuo, art. 12 del regolamento contrattuale; poi 4,00 % annuo, assemblea del 10/03/2025, '
        . 'verbale n. 3, all\'unanimità)');
});

it('due tassi scritti con due riferimenti da 150 caratteri: la causale non sta nella scrittura, niente si scrive e la frase dice come fare', function () {
    $s = accViaVerdi($this);
    rdtRegola($this, $s, ['dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'regolamento', 'riferimento' => RDT_ALTRO_LUNGO])->assertRedirect();
    rdtRegola($this, $s, ['dal' => '2026-05-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '4', 'fonte' => 'delibera', 'riferimento' => RDT_LUNGO])->assertRedirect();
    $this->travelTo('2026-06-29 10:00:00');

    // 31 + 2 + (14 + 150) + 6 + (14 + 150) + 1 = 368 caratteri, oltre i 245 che la scrittura tiene dopo «Addebito: ».
    $prima = RataQuote::where('tipo', RataQuote::TIPO_ACCESSORIO)->count();
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertStatus(422)
        ->assertJsonValidationErrors(['interessi' => 'Per Neri gli interessi passano per più tassi scritti, e con i loro riferimenti la riga dell\'addebito è troppo lunga. '
            . 'Registrali in due volte: prima a una data prima del 01/05/2026, poi alla data che vuoi.']);
    expect(RataQuote::where('tipo', RataQuote::TIPO_ACCESSORIO)->count())->toBe($prima);

    // Fino al 30/04 un tasso solo: la causale sta, con il riferimento intero. 300,00 × 3,00 % × 30/365 = 0,739726 → € 0,74.
    $proposta = accProposta($this, $s, ['Neri'], '2026-04-30')->json();
    expect($proposta['totale'])->toBe(74);
    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    expect(rdtCausali($rataId)[0])->toBe('Interessi di mora al 30/04/2026 (3,00 % annuo, ' . RDT_ALTRO_LUNGO . ')');
});

it('il riferimento intero nel segnaposto [tasso] delle lettere', function () {
    $s = accViaVerdi($this);
    rdtRegola($this, $s, ['dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'delibera', 'riferimento' => RDT_LUNGO])->assertRedirect();
    $this->travelTo('2026-07-10 10:00:00');
    expect(RegoleDelRecupero::tassoPerLaLettera((new RegoleDelRecupero())->delCondominio((int) $s['c']->id), '2026-07-10'))->toBe('3,00 % annuo, ' . RDT_LUNGO);
    $a = $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), ['tipo' => 'sollecito',
        'persone' => [$s['persone']['Neri']->id], 'testo' => "Gentile [nome],\nal tasso [tasso].", 'termine' => 15, 'indirizzi' => ['email']])->assertOk()->json();
    expect($a['tasso'])->toBe('3,00 % annuo, ' . RDT_LUNGO);
});
