<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 — l'erede con un credito paga una sua rata e il debito ereditato nello stesso incasso: il credito usato è quello che il modulo mostra.
 *
 * Il modulo dell'incasso (`IncassoRateNew.vue`) pianifica il credito con `pianificaCoperture(…, centesimi(importoNumerico.value), …)`, cioè con il
 * versamento **senza** la parte sul debito ereditato. Il salvataggio (`StoreIncassoRateAction::pianoDelCredito()`) la pianificava con `importo_totale`, che
 * dalla decisione 529 la contiene: con «prima i soldi» lo scoperto del server era più piccolo di quello del modulo, il credito non si usava, la rata restava
 * aperta della cifra del credito e il credito restava lì, senza un errore. Ora il server toglie la parte sul debito ereditato, come la pagina.
 *
 * Il caso: Bianchi, erede di Ettore Fabbri al 50 % e condòmina (`InteressiSulDebitoEreditatoScenario.php`), ha € 50,00 di credito sulla sua rata 1 e paga
 * il 25/11 la sua rata 2 di € 180,00 (€ 50,00 dal credito, € 130,00 col denaro) e € 150,00 sul debito ereditato: € 280,00 versati.
 *
 * Che cosa NON copre: gli incassi d'erede con un credito già registrati dalla beta.12 (pochi: una frase nel changelog); la rotta dell'ordine
 * (`OrdineImputazione::perRichiesta()`), che riceve dal modulo il versamento già senza la parte ereditata e non cambia.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use App\Services\Gestionale\PianoCreditoIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

it('l\'erede con € 50,00 di credito paga la sua rata 2 di € 180,00 e € 150,00 sul debito ereditato: la rata 2 chiusa, il credito usato, nessun residuo', function () {
    $s = ideCaso($this);
    $dati = ideSuccessione($s);
    $dati['eredi'] = [['anagrafica_id' => $s['persone']['Bianchi']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Marco']->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['persone']['Marco']->id;
    $p = ideRegistra($this, $s, $dati);
    $this->travelTo('2026-11-21 10:00:00');
    lcdEmetti($this, $s, [2], '2026-11-21');
    $q1 = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Bianchi']->id)->firstOrFail();
    $q2 = RataQuote::where('rata_id', lcdRata($s, 2))->where('anagrafica_id', $s['persone']['Bianchi']->id)->firstOrFail();
    $r1 = (int) $q1->importo - (int) $q1->importo_pagato;
    $r2 = (int) $q2->importo - (int) $q2->importo_pagato;
    expect($r2)->toBe(18000);
    // Il credito di € 50,00: la rata 1 pagata con € 50,00 in più.
    $this->travelTo('2026-11-22 10:00:00');
    lcdIncassa($this, $s, 'Bianchi', [$q1->id => $r1], '2026-11-22', 5000);
    expect((int) $q1->fresh()->credito_disponibile)->toBe(5000);

    // Quello che il modulo mostra, con la stessa regola: € 50,00 dal credito, € 130,00 col denaro sulla rata 2.
    $modulo = PianoCreditoIncasso::pianifica([['chiave' => 0, 'importo' => $r2, 'gestione' => $s['g']->id]], [['chiave' => 1, 'importo' => 5000, 'gestione' => $s['g']->id]],
        $r2 - 5000, false, null);
    expect($modulo['usato'])->toBe(5000)->and($modulo['contante'][0])->toBe(13000);

    $this->travelTo('2026-11-25 11:00:00');
    $righe = [['rata_id' => $q2->id, 'importo' => '180.00'], ['rata_id' => $q1->id, 'importo' => '-50.00']];
    // La rotta dell'ordine riceve quello che il modulo manda (`datiOrdine()`): il versamento già senza la parte ereditata, senza `debito_ereditato`.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.ordine', [$s['c']]), [
        'pagante_id' => $s['persone']['Bianchi']->id, 'gestione_id' => $s['g']->id, 'importo_totale' => 130.00, 'eccedenza' => '0.00', 'dettaglio_pagamenti' => $righe,
    ])->assertOk()->assertJsonPath('esito', OrdineImputazione::ORDINE_SCADENZE);

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Bianchi']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-11-25', 'importo_totale' => '280.00',
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => $righe,
        'debito_ereditato' => [['subentro_id' => $p->id, 'importo' => '150.00']],
    ])->assertSessionHasNoErrors();

    // Con il codice di prima: la rata 2 pagata per € 130,00 (aperta di € 50,00) e il credito ancora di € 50,00.
    $q2 = $q2->fresh();
    expect((int) $q2->importo - (int) $q2->importo_pagato)->toBe(0)
        ->and((int) $q1->fresh()->credito_disponibile)->toBe(0)
        ->and((int) RataQuote::where('anagrafica_id', $s['persone']['Bianchi']->id)->sum('credito_disponibile'))->toBe(0)
        // I € 150,00 sul debito ereditato vanno dove andavano: la parte di Bianchi della rata di Ettore chiusa.
        ->and(app(\App\Services\Gestionale\Accessori\VersamentoDegliEredi::class)->apertiDi((int) $s['c']->id, (int) $s['persone']['Bianchi']->id))->toBe([])
        // L'ordine delle scadenze si legge sul denaro delle rate (Coda 713): la rata 2 chiusa, nell'ordine.
        ->and(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail()->imputazione)->toBe(OrdineImputazione::ORDINE_SCADENZE);
});
