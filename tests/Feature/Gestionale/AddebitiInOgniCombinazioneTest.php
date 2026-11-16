<?php

/**
 * Gli addebiti a una persona sola in ogni combinazione, letti in tutte le viste: le righe della scrittura, l'estratto conto della persona, il rendiconto
 * (il riepilogo, la situazione patrimoniale, il conguaglio, i controlli) e il registro di contabilità dicono la stessa cifra, quella fatta a mano.
 *
 * La base è Via Roma (`RendicontoViaRomaScenario.php`, fatta dalle rotte delle pagine): Ugo su A e Bice su C, 500 e 500 millesimi; 12 rate da € 171,25 a
 * testa, emesse il 31/01; Ugo ha versato tutto, Bice € 1.645,00 (restano € 410,00). Le viste si leggono il 15/01/2027, con il 2026 ancora aperto. Le cifre
 * di partenza, a mano: banca 5.000 + 3.700 + 240 − (2.402,50 + 1.182,50 + 40 + 96) = € 5.219,00; crediti verso i condòmini € 410,00 (Bice); quote a giornale
 * € 4.110,00, costi € 4.131,00, risultato € −21,00; A di ognuno € 1.989,25 (le spese da dividere € 3.978,50 a metà), B € 2.055,00, conguaglio € −65,75.
 * Ogni caso registra dalle rotte e dice a mano di quanto cambia ogni cifra. Centesimi.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Accessori\AccessoriDaIncassare;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/LetturaDebitiProva.php';
require_once __DIR__.'/AddebitiInOgniCombinazioneScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** I controlli di Via Roma a esercizio finito: tredici tornano, R8 da leggere per costruzione, la banca senza il suo saldo nella nota. */
function aocControlliDellaBase(): array
{
    return ['PD' => 'quadra', 'R6' => 'quadra', 'R7' => 'quadra', 'R8' => 'segnalazione', 'R2' => 'quadra', 'CN' => 'quadra', 'AC' => 'quadra', 'CR' => 'quadra',
        'CP' => 'quadra', 'GE' => 'quadra', 'CG' => 'quadra', 'CU' => 'quadra', 'CB' => 'quadra', 'CA' => 'quadra', 'BC' => 'non_calcolato'];
}

/** Il giorno in cui si leggono le viste del 2026: il 15/01/2027, il 2026 ancora aperto. */
function aocLeggi($test, array $s, ?Esercizio $e = null): array
{
    vrmGiorno($test, '2027-01-15');

    return aocRendiconto($test, $s, $e ?? $s['e']);
}

/** Una voce di spesa del piano dei conti dell'ordinaria con il preventivo a zero, come la crea l'amministratore: riceve il denaro di una spesa per il ritardo. */
function aocSpesePostali($test, array $s): \App\Models\Gestionale\Conto
{
    return be52Voce($test, $s, $s['pc'], 'Spese postali', 'spesa', '0,00');
}

// ─── Il contributo dall'estratto conto ─────────────────────────────────────────────────────────────────────────────────

it('il contributo libero di € 50,00 a Bice: emesso su «Gestione rate», nell\'estratto, nella riga «addebiti» del riepilogo, nella posizione e non in B; versato, stornato e annullato, ogni vista lo segue', function () {
    $s = vrmScenario($this);
    $base = aocLeggi($this, $s);
    expect($base['situazione'])->toBe(['crediti' => 41000, 'banca' => 521900, 'gestione_rate' => 411000, 'accessori' => 0])
        ->and($base['registro'])->toBe(['entrate' => 394000, 'uscite' => 372100, 'saldo' => 521900, 'righe' => 7])
        ->and(array_intersect_key($base['riepilogo'], array_flip(['contributi', 'quote', 'costi', 'risultato'])))->toBe(['contributi' => 0, 'quote' => 411000, 'costi' => 413100, 'risultato' => -2100])
        ->and($base['conguaglio']['bice'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 41000, 34425])
        ->and($base['conguaglio']['ugo'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 0, -6575])
        ->and($base['controlli'])->toBe(aocControlliDellaBase())
        ->and(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 205500, 'versato' => 164500, 'saldo' => 41000, 'lista' => 0, 'lista_versato' => 0]);

    vrmGiorno($this, '2026-12-20');
    $rataId = aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '50,00', 'causale' => 'Contributo per la cantina', 'scadenza' => '2026-12-20']])
        ->assertCreated()->json('rate.0');
    expect(aocScrittura($s, (int) aocQuota($rataId)->scrittura_contabile_id, $rataId))->toBe([
        'righe' => [['crediti_condomini', 'dare', 5000, 'bice', 'C', true], ['gestione_rate', 'avere', 5000, null, null, false]],
        'gestione' => (int) $s['g']->id, 'esercizio' => (int) $s['e']->id, 'giorno' => '2026-12-20',
    ]);

    $dopo = aocLeggi($this, $s);
    // 2.055 + 50 di addebiti; 410 + 50 di saldo; la lista dell'estratto dice lo stesso addebito.
    expect(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 210500, 'versato' => 164500, 'saldo' => 46000, 'lista' => 5000, 'lista_versato' => 0])
        // La riga sua nel blocco dell'ordinaria e nel raccordo; le quote a giornale 4.110 + 50; il risultato −21 + 50.
        ->and($dopo['riepilogo'])->toBe(['righe' => [$s['g']->id => ['addebiti' => [5000, 5000]]], 'contributi' => 5000, 'quote' => 416000, 'costi' => 413100, 'risultato' => 2900, 'accessori' => 0])
        ->and($dopo['situazione'])->toBe(['crediti' => 46000, 'banca' => 521900, 'gestione_rate' => 416000, 'accessori' => 0])
        // B senza l'addebito: il conguaglio −65,75 non cambia; la posizione 410 + 50; il saldo −65,75 + 460.
        ->and($dopo['conguaglio']['bice'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 46000, 39425])
        ->and($dopo['conguaglio']['ugo'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 0, -6575])
        ->and($dopo['controlli'])->toBe(aocControlliDellaBase())
        // L'emissione non è un movimento di cassa.
        ->and($dopo['registro'])->toBe($base['registro']);

    // Versato tutto il 28/12: una riga di cassa da € 50,00; il debito torna quello di prima.
    vrmGiorno($this, '2026-12-28');
    $incasso = vrmIncassa($this, $s, 'bice', [aocQuota($rataId)->id => 5000], '2026-12-28');
    $versato = aocLeggi($this, $s);
    expect(aocScrittura($s, $incasso, $rataId)['righe'])->toBe([['cassa', 'dare', 5000, null, null, false], ['crediti_condomini', 'avere', 5000, 'bice', 'C', true]])
        ->and(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 210500, 'versato' => 169500, 'saldo' => 41000, 'lista' => 5000, 'lista_versato' => 5000])
        ->and($versato['riepilogo']['righe'])->toBe([$s['g']->id => ['addebiti' => [5000, 0]]])
        ->and($versato['situazione'])->toBe(['crediti' => 41000, 'banca' => 526900, 'gestione_rate' => 416000, 'accessori' => 0])
        ->and($versato['conguaglio']['bice'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 41000, 34425])
        ->and($versato['controlli'])->toBe(aocControlliDellaBase())
        // 3.940 + 50 di entrate; 5.219 + 50 di saldo; una riga in più.
        ->and($versato['registro'])->toBe(['entrate' => 399000, 'uscite' => 372100, 'saldo' => 526900, 'righe' => 8]);
    aocAnnulla($this, $s, $rataId)->assertStatus(422)->assertJsonPath('errors.addebito.0', AddebitiIndividuali::FRASE_NON_ANNULLABILE);

    // Il versamento torna indietro il 30/12: il debito torna aperto; nel registro restano l'incasso e la sua rettifica.
    vrmGiorno($this, '2026-12-30');
    lcdStorna($this, $s, $incasso);
    $stornato = aocLeggi($this, $s);
    expect(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 210500, 'versato' => 164500, 'saldo' => 46000, 'lista' => 5000, 'lista_versato' => 0])
        ->and($stornato['riepilogo']['righe'])->toBe([$s['g']->id => ['addebiti' => [5000, 5000]]])
        ->and($stornato['situazione'])->toBe(['crediti' => 46000, 'banca' => 521900, 'gestione_rate' => 416000, 'accessori' => 0])
        ->and($stornato['conguaglio']['bice'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 46000, 39425])
        ->and($stornato['controlli'])->toBe(aocControlliDellaBase())
        // 3.721 + 50 di uscite: la rettifica; il saldo di prima.
        ->and($stornato['registro'])->toBe(['entrate' => 399000, 'uscite' => 377100, 'saldo' => 521900, 'righe' => 9]);

    // Stornato l'incasso, l'addebito si annulla: le viste tornano quelle di partenza, salvo le due righe di cassa che si annullano fra loro.
    aocAnnulla($this, $s, $rataId)->assertOk();
    $annullato = aocLeggi($this, $s);
    expect(Rata::find($rataId))->toBeNull()
        ->and(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 205500, 'versato' => 164500, 'saldo' => 41000, 'lista' => 0, 'lista_versato' => 0])
        ->and($annullato['riepilogo'])->toBe($base['riepilogo'])
        ->and($annullato['situazione'])->toBe($base['situazione'])
        ->and($annullato['conguaglio'])->toBe($base['conguaglio'])
        ->and($annullato['controlli'])->toBe(aocControlliDellaBase())
        ->and($annullato['registro'])->toBe(['entrate' => 399000, 'uscite' => 377100, 'saldo' => 521900, 'righe' => 9])
        ->and(lcdProvaPersone($this, $this->user, $s['c']->id, $s['e']->id, ['bice' => $s['persone']['bice']->id, 'ugo' => $s['persone']['ugo']->id]))->toBe([]);
});

it('l\'incasso parziale: Bice versa € 20,00 dei € 50,00; restano € 30,00 in ogni vista, e l\'addebito non si annulla', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $rataId = aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '50,00', 'scadenza' => '2026-12-20']])->assertCreated()->json('rate.0');
    vrmGiorno($this, '2026-12-28');
    vrmIncassa($this, $s, 'bice', [aocQuota($rataId)->id => 2000], '2026-12-28');
    $v = aocLeggi($this, $s);

    // 1.645 + 20 di versato; 460 − 20 di saldo.
    expect(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 210500, 'versato' => 166500, 'saldo' => 44000, 'lista' => 5000, 'lista_versato' => 2000])
        ->and($v['riepilogo']['righe'])->toBe([$s['g']->id => ['addebiti' => [5000, 3000]]])
        ->and($v['situazione'])->toBe(['crediti' => 44000, 'banca' => 523900, 'gestione_rate' => 416000, 'accessori' => 0])
        ->and($v['conguaglio']['bice'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 44000, 37425])
        ->and($v['controlli'])->toBe(aocControlliDellaBase())
        ->and($v['registro'])->toBe(['entrate' => 396000, 'uscite' => 372100, 'saldo' => 523900, 'righe' => 8])
        ->and(lcdProvaPersone($this, $this->user, $s['c']->id, $s['e']->id, ['bice' => $s['persone']['bice']->id]))->toBe([]);
    aocAnnulla($this, $s, $rataId)->assertStatus(422)->assertJsonPath('errors.addebito.0', AddebitiIndividuali::FRASE_NON_ANNULLABILE);
});

// ─── La spesa già fatturata ────────────────────────────────────────────────────────────────────────────────────────────

/** La fattura dello studio del 10/12 di € 25,00 con la riga sull'unità C, dalla rotta delle fatture: l'anticipazione dell'unità. Restituisce la riga. */
function aocFatturaSuC($test, array $s): int
{
    vrmGiorno($test, '2026-12-10');
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $s['fornitore'], 0, 0, [
        'data_documento' => '2026-12-10', 'data_scadenza' => '2027-01-10',
        'righe' => [['descrizione' => 'Sollecito a Bice', 'importo_imponibile' => 25.00, 'aliquota_iva' => 0, 'conto_id' => null, 'immobile_id' => $s['unita']['C']->id,
            'is_sopravvenienza' => true, 'concorre_base_ritenuta' => true]],
    ], 'FT-4'))->assertSessionHasNoErrors();

    return (int) DB::table('righe_fattura as rf')->join('fatture_passive as fp', 'fp.id', '=', 'rf.fattura_passiva_id')->where('fp.numero_documento', 'FT-4')
        ->where('fp.condominio_id', $s['c']->id)->value('rf.id');
}

dataset('la natura della spesa fatturata', [
    'una spesa di una sola unità (contributo)' => ['contributo', RataQuote::TIPO_ADDEBITO],
    'una spesa per il ritardo con la fattura (accessorio)' => ['accessorio', RataQuote::TIPO_ACCESSORIO],
]);

it('la spesa già fatturata con la riga su C, addebitata a Bice: l\'emissione chiude l\'anticipazione, la spesa è chiesta una volta sola, i crediti non cambiano', function (string $natura, string $tipo) {
    $s = vrmScenario($this);
    $riga = aocFatturaSuC($this, $s);
    $prima = aocLeggi($this, $s);
    // L'anticipazione: € 25,00 sui crediti con l'unità e senza persona; fra le spese personali di Bice non ancora chieste, dentro il suo conguaglio
    // (1.989,25 + 25,00 − 2.055,00 = −40,75) e il saldo (−40,75 + 410,00).
    expect($prima['situazione']['crediti'])->toBe(43500)
        ->and($prima['conguaglio']['bice'][$s['g']->id])->toBe([198925, 2500, 205500, -4075, 41000, 36925])
        ->and($prima['controlli'])->toBe(aocControlliDellaBase());

    vrmGiorno($this, '2026-12-20');
    $rataId = aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'natura' => $natura, 'riga_fattura_id' => $riga,
        'scadenza' => '2026-12-20']])->assertCreated()->json('rate.0');
    expect(aocQuota($rataId)->tipo)->toBe($tipo)
        ->and(aocScrittura($s, (int) aocQuota($rataId)->scrittura_contabile_id, $rataId))->toBe([
            'righe' => [['crediti_condomini', 'dare', 2500, 'bice', 'C', true], ['crediti_condomini', 'avere', 2500, null, 'C', true]],
            'gestione' => (int) $s['g']->id, 'esercizio' => (int) $s['e']->id, 'giorno' => '2026-12-20',
        ])
        ->and(DB::table('righe_riparto')->where('riga_fattura_id', $riga)->where('tipo', 'ad_personam')->sum('importo'))->toEqual(2500);

    $dopo = aocLeggi($this, $s);
    // 2.055 + 25 di addebiti; 410 + 25 di saldo.
    expect(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 208000, 'versato' => 164500, 'saldo' => 43500, 'lista' => 2500, 'lista_versato' => 0])
        // La riga delle spese chieste a una persona, non dei contributi; le quote a giornale e i costi non cambiano.
        ->and($dopo['riepilogo'])->toBe(['righe' => [$s['g']->id => ['spese_addebitate' => [2500, 2500]]], 'contributi' => 0, 'quote' => 411000, 'costi' => $prima['riepilogo']['costi'],
            'risultato' => $prima['riepilogo']['risultato'], 'accessori' => 0])
        // L'anticipazione dell'unità passa alla persona: i crediti restano € 435,00.
        ->and($dopo['situazione'])->toBe($prima['situazione'])
        // Le personali non chieste a zero (il conguaglio torna −65,75), la posizione 410 + 25, il saldo come prima: chiesta una volta sola.
        ->and($dopo['conguaglio']['bice'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 43500, 36925])
        ->and($dopo['controlli'])->toBe(aocControlliDellaBase())
        ->and($dopo['registro'])->toBe($prima['registro']);

    // La stessa riga una seconda volta: niente resta da chiedere.
    aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '1,00', 'natura' => $natura, 'riga_fattura_id' => $riga]])->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'Di questa spesa restano da chiedere € 0,00: il resto è già chiesto con le rate o con un altro addebito.');

    // Annullato, la spesa torna fra le personali non chieste.
    aocAnnulla($this, $s, $rataId)->assertOk();
    $annullato = aocLeggi($this, $s);
    expect($annullato['conguaglio'])->toBe($prima['conguaglio'])->and($annullato['situazione'])->toBe($prima['situazione'])
        ->and($annullato['riepilogo'])->toBe($prima['riepilogo'])->and($annullato['controlli'])->toBe(aocControlliDellaBase());
})->with('la natura della spesa fatturata');

// ─── Le spese del ritardo senza fattura ────────────────────────────────────────────────────────────────────────────────

it('le spese del ritardo senza fattura, € 12,00 a Bice: su «Accessori da incassare», fuori da quote e costi; versate vanno a «Spese postali» e riducono le spese di tutti; lo storno le rovescia', function () {
    $s = vrmScenario($this);
    $postali = aocSpesePostali($this, $s);
    $base = aocLeggi($this, $s);

    vrmGiorno($this, '2026-12-20');
    $rataId = aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '12,00', 'causale' => 'Spese della raccomandata', 'natura' => 'accessorio',
        'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1', 'conto_id' => $postali->id, 'scadenza' => '2026-12-20']])
        ->assertCreated()->json('rate.0');
    expect(aocScrittura($s, (int) aocQuota($rataId)->scrittura_contabile_id, $rataId)['righe'])
        ->toBe([['crediti_condomini', 'dare', 1200, 'bice', 'C', true], [AccessoriDaIncassare::RUOLO, 'avere', 1200, null, null, false]]);

    $dopo = aocLeggi($this, $s);
    // 2.055 + 12 di addebiti; 410 + 12 di saldo.
    expect(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 206700, 'versato' => 164500, 'saldo' => 42200, 'lista' => 1200, 'lista_versato' => 0])
        // Né una quota, né un costo, né un ricavo finché non è versato.
        ->and($dopo['riepilogo'])->toBe($base['riepilogo'])
        ->and($dopo['situazione'])->toBe(['crediti' => 42200, 'banca' => 521900, 'gestione_rate' => 411000, 'accessori' => 1200])
        ->and($dopo['conguaglio']['bice'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 42200, 35625])
        ->and($dopo['a_di_tutti'])->toBe($base['a_di_tutti'])
        ->and($dopo['controlli'])->toBe(aocControlliDellaBase())
        ->and($dopo['registro'])->toBe($base['registro']);

    // Versate il 28/12: la riga di cassa una volta sola; la coppia verso la voce.
    vrmGiorno($this, '2026-12-28');
    $incasso = vrmIncassa($this, $s, 'bice', [aocQuota($rataId)->id => 1200], '2026-12-28');
    $mastro = (string) DB::table('conti_contabili')->where('id', $postali->conto_contabile_id)->value('ruolo');
    $righe = aocScrittura($s, $incasso, $rataId)['righe'];
    expect($righe)->toBe([['cassa', 'dare', 1200, null, null, false], ['crediti_condomini', 'avere', 1200, 'bice', 'C', true],
        [AccessoriDaIncassare::RUOLO, 'dare', 1200, 'bice', null, false], [$mastro, 'avere', 1200, 'bice', null, false]])
        ->and(DB::table('righe_scritture')->where('scrittura_id', $incasso)->where('tipo_riga', 'avere')->whereNotNull('voce_spesa_id')->pluck('voce_spesa_id')->all())->toBe([$postali->id]);
    $versato = aocLeggi($this, $s);
    expect(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 206700, 'versato' => 165700, 'saldo' => 41000, 'lista' => 1200, 'lista_versato' => 1200])
        // 4.131 − 12 di costi; −21 + 12 di risultato; il versato per interessi e spese.
        ->and($versato['riepilogo'])->toBe(['righe' => [], 'contributi' => 0, 'quote' => 411000, 'costi' => 411900, 'risultato' => -900, 'accessori' => 1200])
        ->and($versato['situazione'])->toBe(['crediti' => 41000, 'banca' => 523100, 'gestione_rate' => 411000, 'accessori' => 0])
        // Le spese da dividere 3.978,50 − 12: € 6,00 in meno a testa, il moroso compreso.
        ->and($versato['a_di_tutti'])->toBe(396650)
        ->and($versato['conguaglio']['bice'][$s['g']->id])->toBe([198325, 0, 205500, -7175, 41000, 33825])
        ->and($versato['conguaglio']['ugo'][$s['g']->id])->toBe([198325, 0, 205500, -7175, 0, -7175])
        ->and($versato['controlli'])->toBe(aocControlliDellaBase())
        // Una riga sola da € 12,00: la coppia verso la voce non è un movimento di cassa.
        ->and($versato['registro'])->toBe(['entrate' => 395200, 'uscite' => 372100, 'saldo' => 523100, 'righe' => 8]);

    // Il versamento torna indietro il 30/12: tutto come all'addebito, e nel registro l'incasso e la sua rettifica.
    vrmGiorno($this, '2026-12-30');
    lcdStorna($this, $s, $incasso);
    $stornato = aocLeggi($this, $s);
    expect($stornato['riepilogo'])->toBe($dopo['riepilogo'])->and($stornato['situazione'])->toBe($dopo['situazione'])
        ->and($stornato['conguaglio'])->toBe($dopo['conguaglio'])->and($stornato['a_di_tutti'])->toBe($base['a_di_tutti'])
        ->and($stornato['controlli'])->toBe(aocControlliDellaBase())
        ->and($stornato['registro'])->toBe(['entrate' => 395200, 'uscite' => 373300, 'saldo' => 521900, 'righe' => 9])
        ->and(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 206700, 'versato' => 164500, 'saldo' => 42200, 'lista' => 1200, 'lista_versato' => 0]);

    // Stornato, si annulla, e «Accessori da incassare» torna a zero.
    aocAnnulla($this, $s, $rataId)->assertOk();
    $annullato = aocLeggi($this, $s);
    expect($annullato['situazione'])->toBe($base['situazione'])->and($annullato['conguaglio'])->toBe($base['conguaglio'])
        ->and($annullato['riepilogo'])->toBe($base['riepilogo'])->and($annullato['controlli'])->toBe(aocControlliDellaBase());
});

it('le spese del ritardo in blocco dall\'elenco delle rate scadute: € 5,00 a Ugo e a Bice sono due rate; annullata quella di Ugo resta quella di Bice', function () {
    $s = vrmScenario($this);
    $postali = aocSpesePostali($this, $s);
    vrmGiorno($this, '2026-12-20');
    $p = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.spese.proposta', [$s['c']]) . '?' . http_build_query(['persone' => [$s['persone']['ugo']->id, $s['persone']['bice']->id]]))
        ->assertOk()->json();
    // Ugo non ha rate scadute: la sua unità è quella di cui è titolare; Bice quella con lo scaduto.
    expect(collect($p['persone'])->map(fn ($x) => [$x['nome'], $x['immobile_id']])->all())->toBe([['Ugo', $s['unita']['A']->id], ['Bice', $s['unita']['C']->id]])
        ->and($p['dove']['proposta'])->toBe($s['g']->id);
    $rate = aocAddebita($this, $s, array_map(fn ($x) => ['chi' => strtolower($x['nome']), 'unita' => $x['immobile_id'] === $s['unita']['A']->id ? 'A' : 'C', 'euro' => '5,00',
        'causale' => 'Spese di sollecito', 'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'regolamento', 'riferimento' => 'art. 20',
        'conto_id' => $postali->id, 'scadenza' => '2026-12-20'], $p['persone']))->assertCreated()->json('rate');
    expect($rate)->toHaveCount(2);

    $v = aocLeggi($this, $s);
    expect(aocEstratto($this, $s, 'ugo')['saldo'])->toBe(500)
        ->and(aocEstratto($this, $s, 'bice')['saldo'])->toBe(41500)
        // 410 + 5 + 5 di crediti; 5 + 5 da incassare.
        ->and($v['situazione'])->toBe(['crediti' => 42000, 'banca' => 521900, 'gestione_rate' => 411000, 'accessori' => 1000])
        ->and([$v['conguaglio']['ugo'][$s['g']->id][4], $v['conguaglio']['bice'][$s['g']->id][4]])->toBe([500, 41500])
        ->and($v['controlli'])->toBe(aocControlliDellaBase());

    aocAnnulla($this, $s, $rate[0])->assertOk();
    $v = aocLeggi($this, $s);
    expect(aocEstratto($this, $s, 'ugo')['saldo'])->toBe(0)
        ->and(aocEstratto($this, $s, 'bice')['saldo'])->toBe(41500)
        ->and($v['situazione'])->toBe(['crediti' => 41500, 'banca' => 521900, 'gestione_rate' => 411000, 'accessori' => 500])
        ->and($v['controlli'])->toBe(aocControlliDellaBase());
});

// ─── Il credito usato ──────────────────────────────────────────────────────────────────────────────────────────────────

it('il credito usato: Ugo ha € 50,00 in più sulla rata 3; il contributo di € 50,00 si compensa senza un movimento di cassa, e non si annulla più', function () {
    $s = vrmScenario($this, 5000);
    $base = aocLeggi($this, $s);
    // Ugo ha versato 2.055 + 50: un credito di € 50,00 sulla rata 3; i crediti verso i condòmini 410 − 50; la banca 5.219 + 50.
    expect(aocEstratto($this, $s, 'ugo'))->toBe(['addebiti' => 205500, 'versato' => 210500, 'saldo' => -5000, 'lista' => 0, 'lista_versato' => 0])
        ->and($base['situazione'])->toBe(['crediti' => 36000, 'banca' => 526900, 'gestione_rate' => 411000, 'accessori' => 0])
        ->and($base['conguaglio']['ugo'][$s['g']->id])->toBe([198925, 0, 205500, -6575, -5000, -11575]);

    vrmGiorno($this, '2026-12-20');
    $rataId = aocAddebita($this, $s, [['chi' => 'ugo', 'unita' => 'A', 'euro' => '50,00', 'scadenza' => '2026-12-20']])->assertCreated()->json('rate.0');
    vrmGiorno($this, '2026-12-28');
    $compensazione = vrmIncassa($this, $s, 'ugo', [vrmQuote($s, 'ugo')[3] => -5000, aocQuota($rataId)->id => 5000], '2026-12-28');
    $figlia = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $compensazione)->where('tipo_movimento', 'storno_credito')->value('id');
    $rata3 = (int) DB::table('rate_quote')->where('id', vrmQuote($s, 'ugo')[3])->value('rata_id');
    expect(collect(aocScrittura($s, $figlia)['righe'])->map(fn ($r) => array_slice($r, 0, 5))->all())
        ->toBe([['crediti_condomini', 'dare', 5000, 'ugo', 'A'], ['crediti_condomini', 'avere', 5000, 'ugo', 'A']])
        ->and(DB::table('righe_scritture')->where('scrittura_id', $figlia)->orderBy('id')->pluck('rata_id')->map(fn ($id) => (int) $id)->all())->toBe([$rata3, $rataId]);

    $v = aocLeggi($this, $s);
    // L'addebito pagato con il credito: niente da versare, niente credito; 2.055 + 50 + 50 di addebiti (il credito riaperto sulla rata 3) e altrettanti versati.
    expect(aocEstratto($this, $s, 'ugo'))->toBe(['addebiti' => 215500, 'versato' => 215500, 'saldo' => 0, 'lista' => 5000, 'lista_versato' => 5000])
        ->and($v['riepilogo']['righe'])->toBe([$s['g']->id => ['addebiti' => [5000, 0]]])
        ->and($v['situazione'])->toBe(['crediti' => 41000, 'banca' => 526900, 'gestione_rate' => 416000, 'accessori' => 0])
        ->and($v['conguaglio']['ugo'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 0, -6575])
        ->and($v['controlli'])->toBe(aocControlliDellaBase())
        // Nessun movimento di cassa in più.
        ->and($v['registro'])->toBe($base['registro']);
    aocAnnulla($this, $s, $rataId)->assertStatus(422)->assertJsonPath('errors.addebito.0', AddebitiIndividuali::FRASE_NON_ANNULLABILE);
});

// ─── Più gestioni ──────────────────────────────────────────────────────────────────────────────────────────────────────

it('un contributo di € 80,00 a Ugo nella gestione straordinaria: la scrittura e la riga del riepilogo sono della straordinaria, l\'ordinaria non cambia', function () {
    $s = vrmScenario($this);
    $str = be52GestioneStraordinaria($s)['g'];
    $base = aocLeggi($this, $s);
    vrmGiorno($this, '2026-12-20');
    $rataId = aocAddebita($this, $s, [['chi' => 'ugo', 'unita' => 'A', 'euro' => '80,00', 'causale' => 'Contributo lavori della facciata', 'scadenza' => '2026-12-20']], $str->id)
        ->assertCreated()->json('rate.0');
    $piano = \App\Models\Gestionale\PianoRate::degliAddebiti()->sole();
    expect([$piano->tipo, (int) $piano->gestione_id])->toBe(['straordinario', (int) $str->id])
        ->and(aocScrittura($s, (int) aocQuota($rataId)->scrittura_contabile_id, $rataId))->toBe([
            'righe' => [['crediti_condomini', 'dare', 8000, 'ugo', 'A', true], ['gestione_rate', 'avere', 8000, null, null, false]],
            'gestione' => (int) $str->id, 'esercizio' => (int) $s['e']->id, 'giorno' => '2026-12-20',
        ]);

    $v = aocLeggi($this, $s);
    expect(aocEstratto($this, $s, 'ugo'))->toBe(['addebiti' => 213500, 'versato' => 205500, 'saldo' => 8000, 'lista' => 8000, 'lista_versato' => 0])
        // La riga nel blocco della straordinaria; l'ordinaria senza.
        ->and($v['riepilogo']['righe'])->toBe([$str->id => ['addebiti' => [8000, 8000]]])
        ->and([$v['riepilogo']['contributi'], $v['riepilogo']['quote']])->toBe([8000, 419000])
        ->and($v['situazione'])->toBe(['crediti' => 49000, 'banca' => 521900, 'gestione_rate' => 419000, 'accessori' => 0])
        // Nell'ordinaria il conguaglio di Ugo resta quello di prima; la posizione dell'addebito nella straordinaria.
        ->and($v['conguaglio']['ugo'][$s['g']->id])->toBe($base['conguaglio']['ugo'][$s['g']->id])
        ->and($v['conguaglio']['ugo'][$str->id][4])->toBe(8000)
        ->and($v['controlli'])->toBe(aocControlliDellaBase())
        ->and($v['registro'])->toBe($base['registro']);

    // Versato il 28/12 dalla pagina degli incassi.
    vrmGiorno($this, '2026-12-28');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-12-28', 'importo_totale' => '80.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $str->id, 'dettaglio_pagamenti' => [['rata_id' => aocQuota($rataId)->id, 'importo' => '80.00']],
    ])->assertSessionHasNoErrors();
    $v = aocLeggi($this, $s);
    expect(aocEstratto($this, $s, 'ugo')['saldo'])->toBe(0)
        ->and($v['riepilogo']['righe'])->toBe([$str->id => ['addebiti' => [8000, 0]]])
        ->and($v['situazione']['banca'])->toBe(529900)
        ->and($v['controlli'])->toBe(aocControlliDellaBase())
        ->and($v['registro'])->toBe(['entrate' => 402000, 'uscite' => 372100, 'saldo' => 529900, 'righe' => 8]);
});

// ─── L'esercizio dopo e a cavallo d'anno ───────────────────────────────────────────────────────────────────────────────

it('a cavallo d\'anno: il 20/03/2027, con il 2026 aperto, il contributo nel 2026 ha la data del 31/12/2026 ed è nel rendiconto del 2026; con la data del 2027 si rifiuta', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-03-20');
    aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '50,00', 'scadenza' => '2027-04-10']], null, null, '2027-03-20')->assertStatus(422)
        ->assertJsonPath('errors.data.0', 'La data dell\'addebito va dentro l\'esercizio «Esercizio 2026», dal 01/01/2026 al 31/12/2026: è la data della sua scrittura.');
    $rataId = aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '50,00', 'scadenza' => '2027-04-10']])->assertCreated()->json('rate.0');
    expect(aocScrittura($s, (int) aocQuota($rataId)->scrittura_contabile_id, $rataId))->toBe([
        'righe' => [['crediti_condomini', 'dare', 5000, 'bice', 'C', true], ['gestione_rate', 'avere', 5000, null, null, false]],
        'gestione' => (int) $s['g']->id, 'esercizio' => (int) $s['e']->id, 'giorno' => '2026-12-31',
    ]);

    $v = aocRendiconto($this, $s, $s['e']);
    expect(aocEstratto($this, $s, 'bice'))->toBe(['addebiti' => 210500, 'versato' => 164500, 'saldo' => 46000, 'lista' => 5000, 'lista_versato' => 0])
        ->and($v['riepilogo'])->toBe(['righe' => [$s['g']->id => ['addebiti' => [5000, 5000]]], 'contributi' => 5000, 'quote' => 416000, 'costi' => 413100, 'risultato' => 2900, 'accessori' => 0])
        ->and($v['situazione'])->toBe(['crediti' => 46000, 'banca' => 521900, 'gestione_rate' => 416000, 'accessori' => 0])
        ->and($v['conguaglio']['bice'][$s['g']->id])->toBe([198925, 0, 205500, -6575, 46000, 39425])
        ->and($v['controlli'])->toBe(aocControlliDellaBase())
        ->and($v['registro'])->toBe(['entrate' => 394000, 'uscite' => 372100, 'saldo' => 521900, 'righe' => 7]);
});

it('l\'esercizio dopo: con il 2026 chiuso il contributo del 10/02/2027 si scrive nel 2027; nel 2026 si rifiuta, e il rendiconto del 2026 non cambia', function () {
    $s = vrmScenario($this);
    $base = aocLeggi($this, $s);
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $g27 = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $e27);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);

    vrmGiorno($this, '2027-02-10');
    aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '30,00', 'scadenza' => '2027-02-28']])->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'L\'esercizio «Esercizio 2026» è chiuso: l\'addebito si registra nell\'esercizio aperto.');
    // La finestra dell'estratto conto propone l'ordinaria dell'esercizio aperto di oggi.
    $p = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?anagrafica_id=' . $s['persone']['bice']->id)->assertOk()->json();
    expect([$p['dove']['proposta']['gestione_id'], $p['dove']['proposta']['esercizio_id']])->toBe([(int) $g27->id, (int) $e27->id]);
    $rataId = aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '30,00', 'scadenza' => '2027-02-28']], $g27->id, $e27->id)->assertCreated()->json('rate.0');
    expect(aocScrittura($s, (int) aocQuota($rataId)->scrittura_contabile_id, $rataId))->toBe([
        'righe' => [['crediti_condomini', 'dare', 3000, 'bice', 'C', true], ['gestione_rate', 'avere', 3000, null, null, false]],
        'gestione' => (int) $g27->id, 'esercizio' => (int) $e27->id, 'giorno' => '2027-02-10',
    ]);

    // Il rendiconto del 2026, chiuso, non lo vede (le viste di un esercizio chiuso si leggono il 15/01/2027 come prima).
    $v26 = aocLeggi($this, $s);
    expect($v26['riepilogo'])->toBe($base['riepilogo'])->and($v26['situazione'])->toBe($base['situazione'])
        ->and($v26['conguaglio'])->toBe($base['conguaglio'])->and($v26['registro'])->toBe($base['registro']);

    // Il 2027: la riga «addebiti» della sua ordinaria e l'estratto conto dell'esercizio aperto.
    vrmGiorno($this, '2027-02-15');
    $v27 = aocRendiconto($this, $s, $e27);
    $estratto = app(\App\Services\Gestionale\EstrattoContoDellaPersona::class)->ledger($s['c'], $e27->fresh(), $s['persone']['bice']);
    $riga = $estratto[0]->first(fn ($r) => $r['scrittura_id'] === (int) aocQuota($rataId)->scrittura_contabile_id);
    expect($v27['riepilogo']['righe'])->toBe([$g27->id => ['addebiti' => [3000, 3000]]])
        ->and($v27['riepilogo']['contributi'])->toBe(3000)
        ->and([$riga['dare'], $riga['gestione']])->toBe([3000, $g27->nome])
        ->and(aocEstratto($this, $s, 'bice')['lista'])->toBe(3000);
});

// ─── Il passaggio di proprietà e la successione ────────────────────────────────────────────────────────────────────────

/** Una persona nuova del condominio, come la crea l'anagrafica: chi compra o chi eredita C. */
function aocPersona(array &$s, string $nome): \App\Models\Anagrafica
{
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-aoc' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'AOC' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['persone'][strtolower($nome)] = $p;

    return $p;
}

/** La riga di titolarità di Bice su C. */
function aocRigaDiBice(array $s): int
{
    return (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['bice']->id)->where('immobile_id', $s['unita']['C']->id)->value('id');
}

/** I saldi del passaggio (le coppie del conguaglio e l'arretrato) per persona. */
function aocSaldiDelPassaggio(array $s): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn ($p, $k) => [(int) $p->id => $k])->all();

    return DB::table('saldi')->where('condominio_id', $s['c']->id)->whereNotNull('subentro_id')->groupBy('anagrafica_id')->selectRaw('anagrafica_id, SUM(saldo_iniziale) as s')
        ->pluck('s', 'anagrafica_id')->mapWithKeys(fn ($v, $id) => [$nomi[(int) $id] => (int) $v])->sortKeys()->all();
}

/** Il caso del passaggio: con o senza un contributo di € 50,00 a Bice il 20/11; la vendita di C a Dario dal 01/12, registrata il 05/12. */
function aocVendita($test, bool $conAddebito): array
{
    $s = vrmScenario($test);
    $dario = aocPersona($s, 'Dario');
    if ($conAddebito) {
        vrmGiorno($test, '2026-11-20');
        $s['addebito'] = aocAddebita($test, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '50,00', 'scadenza' => '2026-11-20']])->assertCreated()->json('rate.0');
    }
    vrmGiorno($test, '2026-12-05');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['C']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => aocRigaDiBice($s), 'anagrafica_entrante_id' => $dario->id, 'decorrenza' => '2026-12-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-12-03',
        'estremi_titolo' => 'atto notaio Bruni, rep. 7', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito del 01/12, letto il pannello',
    ])->assertSessionHasNoErrors()->assertRedirect();

    return $s;
}

it('la vendita di C da Bice a Dario dal 01/12 con un contributo di € 50,00 aperto: il contributo resta a Bice in ogni vista; Dario e i saldi del passaggio sono quelli senza l\'addebito', function () {
    $senza = aocVendita($this, false);
    $vSenza = aocLeggi($this, $senza);
    $eSenza = ['bice' => aocEstratto($this, $senza, 'bice'), 'dario' => aocEstratto($this, $senza, 'dario')];
    $con = aocVendita($this, true);
    $vCon = aocLeggi($this, $con);

    expect(aocSaldiDelPassaggio($con))->toBe(aocSaldiDelPassaggio($senza))
        ->and(RataQuote::where('rata_id', $con['addebito'])->pluck('anagrafica_id')->all())->toBe([(int) $con['persone']['bice']->id])
        // Bice: € 50,00 in più negli addebiti e nel saldo; Dario come senza.
        ->and(aocEstratto($this, $con, 'bice'))->toBe(['addebiti' => $eSenza['bice']['addebiti'] + 5000, 'versato' => $eSenza['bice']['versato'], 'saldo' => $eSenza['bice']['saldo'] + 5000,
            'lista' => 5000, 'lista_versato' => 0])
        ->and(aocEstratto($this, $con, 'dario'))->toBe($eSenza['dario'])
        ->and(array_values($vCon['conguaglio']['dario']))->toBe(array_values($vSenza['conguaglio']['dario']))
        ->and($vCon['conguaglio']['bice'][$con['g']->id][3])->toBe($vSenza['conguaglio']['bice'][$senza['g']->id][3])
        ->and($vCon['conguaglio']['bice'][$con['g']->id][4] - $vSenza['conguaglio']['bice'][$senza['g']->id][4])->toBe(5000)
        ->and($vCon['conguaglio']['bice'][$con['g']->id][5] - $vSenza['conguaglio']['bice'][$senza['g']->id][5])->toBe(5000)
        ->and($vCon['situazione']['crediti'] - $vSenza['situazione']['crediti'])->toBe(5000)
        ->and($vCon['riepilogo']['righe'])->toBe([$con['g']->id => ['addebiti' => [5000, 5000]]])
        ->and($vCon['controlli'])->toBe($vSenza['controlli']);
});

/** Il caso della successione: con o senza un contributo di € 50,00 a Bice il 20/11; la successione di C dal 01/12 a Elena e Fabio, il 50 % ciascuno. */
function aocSuccessione($test, bool $conAddebito, string $arretrato): array
{
    $s = vrmScenario($test);
    $elena = aocPersona($s, 'Elena');
    $fabio = aocPersona($s, 'Fabio');
    if ($conAddebito) {
        vrmGiorno($test, '2026-11-20');
        $s['addebito'] = aocAddebita($test, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '50,00', 'scadenza' => '2026-11-20']])->assertCreated()->json('rate.0');
    }
    vrmGiorno($test, '2026-12-05');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['C']]), [
        'tipo' => 'successione', 'riga_uscente_id' => aocRigaDiBice($s), 'decorrenza' => '2026-12-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $elena->id, 'quota' => 50], ['anagrafica_id' => $fabio->id, 'quota' => 50]], 'arretrato' => $arretrato, 'erede_di_riferimento' => $elena->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta',
    ] + ($arretrato === 'defunto' ? ['conguaglio' => \App\Models\Gestionale\Subentro::SCRIVI_IL_CONGUAGLIO] : []))->assertSessionHasNoErrors()->assertRedirect();

    return $s;
}

it('la successione di Bice con l\'arretrato agli eredi: il contributo di € 50,00 passa con l\'arretrato, € 460,00 a metà; l\'estratto di ognuno dice la sua parte', function () {
    $senza = aocSuccessione($this, false, 'eredi');
    $con = aocSuccessione($this, true, 'eredi');
    // L'arretrato di Bice: 410,00 senza l'addebito, 410,00 + 50,00 con; il 50 % a ciascun erede.
    expect(aocSaldiDelPassaggio($senza))->toBe(['bice' => -41000, 'elena' => 20500, 'fabio' => 20500])
        ->and(aocSaldiDelPassaggio($con))->toBe(['bice' => -46000, 'elena' => 23000, 'fabio' => 23000]);

    $v = aocLeggi($this, $con);
    // Ognuno legge nel suo estratto quello che il passaggio gli ha dato: Bice niente, gli eredi € 230,00.
    expect(aocEstratto($this, $con, 'bice')['saldo'])->toBe(0)
        ->and(aocEstratto($this, $con, 'elena')['saldo'])->toBe(23000)
        ->and(aocEstratto($this, $con, 'fabio')['saldo'])->toBe(23000)
        ->and($v['situazione']['crediti'])->toBe(46000)
        ->and($v['riepilogo']['righe'])->toBe([$con['g']->id => ['addebiti' => [5000, 5000]]])
        ->and($v['controlli'])->toBe(aocLeggi($this, $senza)['controlli']);
});

it('la successione con l\'arretrato agli eredi: il contributo che passa con l\'arretrato non entra in B; il conguaglio di ognuno resta quello senza l\'addebito, e il saldo degli eredi lo comprende', function () {
    $senza = aocSuccessione($this, false, 'eredi');
    $vSenza = aocLeggi($this, $senza);
    $con = aocSuccessione($this, true, 'eredi');
    $vCon = aocLeggi($this, $con);
    $riga = fn (array $v, string $chi) => array_values($v['conguaglio'][$chi])[0];
    // Senza l'addebito: Bice A 1.820,30, B 2.055,00 − 410,00 passati agli eredi = 1.645,00, conguaglio 175,30, saldo 410 − 410 + 175,30; gli eredi A 84,48 e
    // 84,47, B 205,00 (le quote passate), conguaglio −120,52 e −120,53, saldo 205,00 − 120,52 = 84,48.
    expect($riga($vSenza, 'bice'))->toBe([182030, 0, 164500, 17530, 41000, 17530])
        ->and($riga($vSenza, 'elena'))->toBe([8448, 0, 20500, -12052, 0, 8448])
        // Con l'addebito: B è delle quote, e l'addebito non è una quota; il conguaglio di nessuno cambia. Il contributo passa agli eredi con l'arretrato, come
        // dicono l'estratto conto e i saldi del passaggio: la posizione di Bice 410 + 50, e il saldo degli eredi 25,00 in più ciascuno (84,48 + 25,00).
        ->and($riga($vCon, 'bice'))->toBe([182030, 0, 164500, 17530, 46000, 17530])
        ->and($riga($vCon, 'elena'))->toBe([8448, 0, 20500, -12052, 0, 10948])
        ->and($riga($vCon, 'fabio'))->toBe([8447, 0, 20500, -12053, 0, 10947]);
});

/** La successione di C, l'ultima registrata. */
function aocPassaggioDiC(array $s): \App\Models\Gestionale\Subentro
{
    return \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['C']->id)->latest('id')->firstOrFail();
}

it('la successione con l\'arretrato agli eredi: la parte del contributo ha le sue righe, «Addebiti agli eredi», nel registro del passaggio; il contributo non si annulla finché il passaggio c\'è, e l\'elenco dice perché', function () {
    $s = aocSuccessione($this, true, 'eredi');
    $passaggio = aocPassaggioDiC($s);
    $nomi = collect($s['persone'])->mapWithKeys(fn ($p, $k) => [(int) $p->id => $k])->all();
    $righe = DB::table('saldi')->whereIn('id', $passaggio->saldiDegliAddebiti())->orderBy('id')->get();
    // € 50,00 a metà: € 25,00 a ciascun erede, − € 50,00 a Bice; i € 410,00 delle quote nelle righe dell'arretrato di sempre. Le righe degli addebiti sono
    // anche righe dell'arretrato, e il totale del passaggio di ognuno non cambia.
    expect($righe->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->mapWithKeys(fn ($v, $id) => [$nomi[(int) $id] => $v])->sortKeys()->all())
        ->toBe(['bice' => -5000, 'elena' => 2500, 'fabio' => 2500])
        ->and($righe->every(fn ($r) => str_starts_with((string) $r->descrizione, 'Addebiti agli eredi, successione del 1 dicembre 2026') && ! (bool) $r->is_applicato))->toBeTrue()
        ->and(array_diff($passaggio->saldiDegliAddebiti(), $passaggio->saldiDellArretrato()))->toBe([])
        ->and($passaggio->registro['arretrato']['addebiti']['rate'])->toBe([(int) $s['addebito']])
        ->and(aocSaldiDelPassaggio($s))->toBe(['bice' => -46000, 'elena' => 23000, 'fabio' => 23000]);

    // L'annullamento lascerebbe agli eredi le righe di un contributo che non c'è più: si rifiuta, e l'elenco di Bice dice perché al posto di «Annulla».
    $frase = sprintf(AddebitiIndividuali::FRASE_PASSATO_AGLI_EREDI, '01/12/2026');
    expect($frase)->toBe('Passato agli eredi con la successione del 01/12/2026: per annullarlo, annulla prima il passaggio.');
    aocAnnulla($this, $s, (int) $s['addebito'])->assertStatus(422)->assertJsonPath('errors.addebito.0', $frase);
    $elenco = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['bice']]))->assertOk()->viewData('page')['props']['addebiti'];
    expect(collect($elenco)->firstWhere('rata_id', (int) $s['addebito']))->toMatchArray(['importo' => 5000, 'annullabile' => false, 'perche_resta' => $frase])
        ->and(RataQuote::where('rata_id', $s['addebito'])->count())->toBe(1);
});

it('annullata la successione con l\'arretrato agli eredi, se ne vanno anche le righe degli addebiti: il contributo torna a Bice, e allora si annulla', function () {
    $s = aocSuccessione($this, true, 'eredi');
    $passaggio = aocPassaggioDiC($s);
    $righe = $passaggio->saldiDegliAddebiti();
    expect($righe)->toHaveCount(4);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['C'], $passaggio]), ['nota_annullamento' => 'Successione registrata per errore'])
        ->assertRedirect();
    // Via tutte le righe del passaggio, anche quelle degli addebiti: Bice ha di nuovo i suoi € 410,00 e il contributo, gli eredi niente.
    expect(DB::table('saldi')->whereIn('id', $righe)->exists())->toBeFalse()
        ->and(aocSaldiDelPassaggio($s))->toBe([])
        ->and(aocEstratto($this, $s, 'bice')['saldo'])->toBe(46000)
        ->and(aocEstratto($this, $s, 'elena')['saldo'])->toBe(0);
    aocAnnulla($this, $s, (int) $s['addebito'])->assertOk();
    expect(aocEstratto($this, $s, 'bice')['saldo'])->toBe(41000);
});

it('la successione di Bice con l\'arretrato a nome della defunta: il contributo resta suo, e i saldi del passaggio sono quelli senza l\'addebito', function () {
    $senza = aocSuccessione($this, false, 'defunto');
    $con = aocSuccessione($this, true, 'defunto');
    expect(aocSaldiDelPassaggio($con))->toBe(aocSaldiDelPassaggio($senza))
        ->and(RataQuote::where('rata_id', $con['addebito'])->pluck('anagrafica_id')->all())->toBe([(int) $con['persone']['bice']->id])
        ->and(aocEstratto($this, $con, 'bice')['saldo'] - aocEstratto($this, $senza, 'bice')['saldo'])->toBe(5000)
        ->and(aocEstratto($this, $con, 'elena'))->toBe(aocEstratto($this, $senza, 'elena'));
    // Il conguaglio: quello di Bice e degli eredi uguale (B senza l'addebito), la posizione di Bice 50,00 in più, e il suo saldo con lei.
    $vSenza = aocLeggi($this, $senza);
    $vCon = aocLeggi($this, $con);
    $riga = fn (array $v, string $chi) => array_values($v['conguaglio'][$chi])[0];
    expect($riga($vCon, 'bice')[3])->toBe($riga($vSenza, 'bice')[3])
        ->and($riga($vCon, 'bice')[4] - $riga($vSenza, 'bice')[4])->toBe(5000)
        ->and($riga($vCon, 'bice')[5] - $riga($vSenza, 'bice')[5])->toBe(5000)
        ->and($riga($vCon, 'elena'))->toBe($riga($vSenza, 'elena'))
        ->and($riga($vCon, 'fabio'))->toBe($riga($vSenza, 'fabio'));
});

// ─── I rifiuti ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('i rifiuti della finestra: gli interessi si propongono, non si scrivono a mano; una spesa senza fattura chiede il tipo e la voce; a chi non ha l\'unità no', function () {
    $s = vrmScenario($this);
    $postali = aocSpesePostali($this, $s);
    vrmGiorno($this, '2026-12-20');
    $rate = Rata::count();
    aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '1,00', 'natura' => 'accessorio', 'tipo_accessorio' => 'interessi', 'base' => 'legge', 'conto_id' => $postali->id]])
        ->assertStatus(422)->assertJsonPath('errors', ['voci.0.tipo_accessorio' => ['Gli interessi di mora si registrano dalla proposta degli interessi, nell\'elenco delle rate scadute: con il calcolo riga per riga.']]);
    aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '5,00', 'natura' => 'accessorio', 'base' => 'delibera', 'conto_id' => $postali->id]])->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'Scegli che cos\'è la spesa: interessi di mora, spese di sollecito, spese legali o altro.');
    aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '5,00', 'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera']])->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'Scegli la voce che riceve il denaro quando la persona lo versa: una voce della gestione «' . $s['g']->nome . '». Se non c\'è, creala nel piano dei conti con il preventivo a zero.');
    // Ugo non ha niente su C.
    aocAddebita($this, $s, [['chi' => 'ugo', 'unita' => 'C', 'euro' => '5,00']])->assertStatus(422)->assertJsonPath('errors.addebiti.0', AddebitiIndividuali::FRASE_INQUILINO);
    aocAddebita($this, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => '0,00']])->assertStatus(422)->assertJsonPath('errors.addebiti.0', 'Scrivi l\'importo dell\'addebito, più di zero.');
    expect(Rata::count())->toBe($rate);
});
