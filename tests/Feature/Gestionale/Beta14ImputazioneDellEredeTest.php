<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Coda 713) — il versamento di un erede sul debito ereditato non si scrive più «fuori ordine, non dichiarata».
 *
 * Il modulo dell'incasso chiede l'ordine delle scadenze senza la parte sul debito ereditato (`IncassoRateNew.vue`, `importoNumerico`), e non chiede mai la
 * dichiarazione; l'azione passava a `OrdineImputazione::esito()` il totale **con dentro** quella parte: € 0,00 sulle rate, € 150,00 «in più», la prima rata
 * aperta dell'erede, e l'incasso finiva «fuori ordine» nel dettaglio, nel filtro dell'elenco e nella K4 della nota sintetica che va ai condòmini
 * («imputati in un altro modo, senza una ragione scritta»). Ora l'azione passa il contante senza la parte ereditata, e un incasso tutto sul debito
 * ereditato ha il suo esito: «Sul debito ereditato».
 *
 * Il caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`): la rata di aprile da € 300,00, il decesso il 17/11/2026, Elena e Marco al 50 %.
 *
 * Che cosa NON copre: gli incassi d'erede già scritti «fuori ordine» con la beta.12 e la .13 (si stornano e si registrano di nuovo: una frase nel
 * changelog); la pagina a video (la via per correggere non elenca il codice nuovo: `IncassoRateShow.vue`, `mostraViaPerCorreggere`).
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** L'incasso dalla rotta: le righe sulle quote (`[id quota => centesimi]`) e il debito ereditato (`[subentro => centesimi]`). Restituisce l'incasso. */
function b14ieIncassa($test, array $s, string $chi, array $righe, array $ereditato, string $data): ScritturaContabile
{
    $test->travelTo($data . ' 11:00:00');
    $totale = array_sum($righe) + array_sum($ereditato);
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $c) => ['rata_id' => (int) $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
        'debito_ereditato' => array_map(fn ($id, $c) => ['subentro_id' => (int) $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($ereditato), array_values($ereditato)),
    ])->assertSessionHasNoErrors();

    return ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
}

/** Un addebito a Elena sull'unità U1, dalla rotta della finestra, con la scadenza data: la sua quota. */
function b14ieAddebito($test, array $s, string $euro, string $scadenza, string $causale): RataQuote
{
    $test->travelTo($scadenza . ' 09:00:00');
    $rata = (int) adbRegistra($test, $s, [['chi' => 'Elena', 'unita' => 'U1', 'euro' => $euro, 'scadenza' => $scadenza, 'causale' => $causale]])->assertCreated()->json('rate.0');

    return RataQuote::where('rata_id', $rata)->where('anagrafica_id', $s['persone']['Elena']->id)->firstOrFail();
}

it('Coda 713, la sonda: Elena, con un suo addebito di € 1,74 aperto, versa € 150,00 sul debito ereditato: «Sul debito ereditato», fuori dal filtro «Fuori ordine» e dalla K4', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    b14ieAddebito($this, $s, '1,74', '2026-11-21', 'Addebito di Elena');

    $incasso = b14ieIncassa($this, $s, 'Elena', [], [$p->id => 15000], '2026-11-25');

    // Con il codice di prima: `fuori_ordine`, «Fuori ordine, non dichiarata», con la frase che invita a stornare e registrare di nuovo.
    expect($incasso->imputazione)->toBe('debito_ereditato')->and($incasso->imputazione_nota)->toBeNull();
    $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.show', [$s['c'], $incasso->id]))->assertOk()
        ->assertInertia(fn ($x) => $x->where('imputazione.codice', 'debito_ereditato')->where('imputazione.testo', 'Sul debito ereditato')->etc());
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c'], 'stato' => 'fuori_ordine']))->assertOk()->viewData('page')['props'];
    expect(array_column($props['movimenti']['data'], 'id'))->toBe([])
        ->and(FattiDellaNota::k4($s['e']->fresh())['frase'])->toBe(FattiDellaNota::K4)
        // La ricevuta non scrive una riga del criterio: dice già «Per il debito ereditato da».
        ->and(RicevutaIncasso::fraseImputazione($incasso->imputazione, $incasso->imputazione_nota))->toBeNull();
});

it('Coda 713, D2: Bianchi, erede e condòmino, versa la sua rata 1 e € 150,00 sul debito ereditato nello stesso incasso: l\'esito delle sue rate, «secondo l\'ordine»', function () {
    $s = ideCaso($this);
    $dati = ideSuccessione($s);
    $dati['eredi'] = [['anagrafica_id' => $s['persone']['Bianchi']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Marco']->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['persone']['Marco']->id;
    $p = ideRegistra($this, $s, $dati);
    $quota = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Bianchi']->id)->firstOrFail();

    $incasso = b14ieIncassa($this, $s, 'Bianchi', [$quota->id => (int) $quota->importo - (int) $quota->importo_pagato], [$p->id => 15000], '2026-11-25');

    // Con il codice di prima i € 150,00 ereditati erano «in più» con le rate 2–4 di Bianchi aperte: «fuori ordine».
    expect($incasso->imputazione)->toBe(OrdineImputazione::ORDINE_SCADENZE)
        ->and(FattiDellaNota::k4($s['e']->fresh())['frase'])->toBe(FattiDellaNota::K4);
});

it('Coda 713: Elena paga € 1,00 su un suo addebito più nuovo, con quello più vecchio aperto, e € 150,00 sul debito ereditato: «fuori ordine» per la sua parte, come sempre', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    b14ieAddebito($this, $s, '1,74', '2026-11-21', 'Addebito vecchio');
    $nuovo = b14ieAddebito($this, $s, '5,00', '2026-11-24', 'Addebito nuovo');

    $incasso = b14ieIncassa($this, $s, 'Elena', [$nuovo->id => 100], [$p->id => 15000], '2026-11-25');

    expect($incasso->imputazione)->toBe(OrdineImputazione::FUORI_ORDINE)
        ->and(FattiDellaNota::k4($s['e']->fresh())['frase'])->toEndWith('1 versamento è stato imputato in un altro modo, senza una ragione scritta.');
});

it('Coda 713: un incasso senza debito ereditato ha l\'esito di sempre', function () {
    $s = ideCaso($this);
    $bianchi = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Bianchi']->id)->firstOrFail();
    $verdi2 = RataQuote::where('rata_id', lcdRata($s, 2))->where('anagrafica_id', $s['persone']['Verdi']->id)->firstOrFail();

    $nellOrdine = b14ieIncassa($this, $s, 'Bianchi', [$bianchi->id => (int) $bianchi->importo - (int) $bianchi->importo_pagato], [], '2026-11-25');
    $fuori = b14ieIncassa($this, $s, 'Verdi', [$verdi2->id => 1000], [], '2026-11-25');

    expect([$nellOrdine->imputazione, $fuori->imputazione])->toBe([OrdineImputazione::ORDINE_SCADENZE, OrdineImputazione::FUORI_ORDINE])
        // Senza la parte ereditata (il parametro di serie: la rotta del modulo non la manda) e senza contante l'esito resta la compensazione.
        ->and(app(OrdineImputazione::class)->esito((int) $s['c']->id, (int) $s['persone']['Elena']->id, [], [], 0)['esito'])->toBe(OrdineImputazione::COMPENSAZIONE);
});

it('Coda 713: l\'esito con la sola parte ereditata è «Sul debito ereditato», e l\'etichetta lo dice', function () {
    $s = ideCaso($this);

    expect(app(OrdineImputazione::class)->esito((int) $s['c']->id, (int) $s['persone']['Elena']->id, [], [], 0, ereditatoCents: 15000)['esito'])->toBe('debito_ereditato')
        ->and(OrdineImputazione::etichetta('debito_ereditato'))->toBe('Sul debito ereditato');
});
