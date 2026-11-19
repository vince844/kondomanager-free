<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Code 686 e 687 (decisioni 506, 507, 510): la spesa non prevista da mettere a rate, dalle rotte vere da amministratore.
 *
 * È la prova di supporto della domanda del forum del 18/11/2026 (scritta sulla 1.11.0 con i `dump`),
 * portata nella suite con le asserzioni del comportamento nuovo: una gestione straordinaria nuova, la voce «Riparazione colonna» da € 1.220,00 sulla tabella
 * di proprietà, la fattura da € 1.000,00 + IVA 22 %, due appartamenti a 600 e 400 millesimi.
 *
 * - A: la fattura sulla voce. Il carrello di «Spese fuori preventivo» è vuoto; il salvataggio senza fatture risponde in parole semplici (686 (b)); ciò che il
 *   carrello non mostra nomina la fattura sulla voce, con la voce da scegliere nell'altra scheda (686 (a)). La strada giusta, «Dalle voci del preventivo»,
 *   dà € 732,00 e € 488,00 (controprova).
 * - A bis: la voce creata a zero e lo sforo. Con «conguaglio di fine anno» il selettore spegne l'unica voce, e il piano fatto dalla pagina nasce a € 0,00:
 *   è il caso del punto (e), che la pagina dice prima di salvare (`PianiRateNewSpesaFuoriPreventivo.test.ts`).
 * - B, B1–B5: la riga «Fuori preventivo». Il carrello guarda la gestione (686 (d)); «Modifica» tiene il segno (B1) e la gestione (B4); ciò che il carrello
 *   non mostra dice l'altro esercizio (B2) e il piano che la contiene già (B3).
 * - Fase 1-bis della beta.12: N4 (una fattura sulla voce mandata in «Spese fuori preventivo» si rifiuta con una frase semplice) e le prove che uccidono le
 *   mutazioni sopravvissute del carrello (M-C3 della lente P, C8).
 *
 * Cosa resta scoperto: la pagina (i vitest della pagina del nuovo piano e di quella di modifica); il controllo delle fatture già toccate
 * (`FattureToccateDallaModificaTest`) e la colonna con la sua migrazione (`FatturaRicordaLaGestioneTest`); «Urgenza» sulle voci (Coda 688, beta.13).
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SpesaNonPrevistaScenario.php';

uses(RefreshDatabase::class);

// --- A: la fattura sulla voce della gestione straordinaria ---------------------------------------------------------------------------------------

it('A, 686 (b) — senza fatture il salvataggio dice «Scegli almeno una fattura da mettere a rate.», non «fatture config è richiesto quando tipo è straordinario.»', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false]);
    expect(snpCarrello($this, $r))->toBe([]);

    $resp = $this->actingAs($r['s']->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Riparazione a rate', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/10/2026', 'data_delibera_assemblea' => '2026-10-15', 'fatture_config' => [], 'genera_subito' => 1,
    ]);
    $resp->assertUnprocessable();
    expect($resp->json('errors.fatture_config'))->toBe(['Scegli almeno una fattura da mettere a rate.']);
});

it('A, 686 (b), D194 — senza «Tipo di autorizzazione» e «Motivazione» i messaggi dicono che cosa scegliere, con le parole della pagina', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r));

    $resp = $this->actingAs($r['s']->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Riparazione a rate', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'tipo_autorizzazione' => '', 'motivazione_autorizzazione' => '', 'data_delibera_assemblea' => '', 'fatture_config' => [['id' => $f->id, 'importo' => '1.220,00']],
    ]);
    $resp->assertUnprocessable();
    expect($resp->json('errors.tipo_autorizzazione'))->toBe(['Scegli il tipo di autorizzazione: «Delibera Assembleare» o «Urgenza».'])
        ->and($resp->json('errors.motivazione_autorizzazione'))->toBe(['Scrivi la motivazione o gli estremi del verbale, per esempio «Verbale del 12/03/2026».'])
        ->and($resp->json('errors.data_delibera_assemblea.0'))->toStartWith('Indica la data della delibera dell\'assemblea');
});

it('A, 686 (a) — ciò che il carrello non mostra: la fattura sulla voce, con fornitore, importo, voce e la voce da scegliere nell\'altra scheda', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false]);

    $fuori = snpFuori($this, $r);
    expect($fuori['gestione'])->toBe('Spesa fuori bilancio')
        ->and($fuori['sulle_voci']['totale'])->toBe(1)
        ->and($fuori['sulle_voci']['fatture'])->toBe([['id' => $f->id, 'fornitore' => 'Idraulica Srl', 'numero_documento' => 'FT-ROB', 'importo' => 1220,
            'voce' => 'Riparazione colonna', 'altre_voci' => 0, 'conto_id' => $voce->id]])
        ->and($fuori['altri_esercizi'])->toBe([])
        ->and($fuori['gia_nei_piani'])->toBe(['totale' => 0, 'fatture' => []]);

    // Sulla gestione ordinaria la fattura della straordinaria non c'è: ogni gestione dice le sue.
    expect(snpFuori($this, $r, $r['s']->gestioni['Ordinaria 2026']['g']->id)['sulle_voci']['totale'])->toBe(0);
});

it('A — controprova: la strada giusta, «Dalle voci del preventivo» sulla stessa gestione con la voce e la delibera, dà € 732,00 e € 488,00', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false]);

    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Riparazione a rate', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'data_delibera_assemblea' => '2026-10-15', 'capitoli_ids' => [$voce->id], 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();

    expect(snpPerPersona($r, PianoRate::where('nome', 'Riparazione a rate')->sole()))->toBe(['ugo' => 73200, 'bice' => 48800]);
});

it('A bis, punto (e) — la voce a zero sforata con «conguaglio di fine anno»: il selettore spegne l\'unica voce, e un piano senza voci nascerebbe a € 0,00', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '0,00');
    snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], ['override_budget' => [
        'motivazione' => 'Riparazione urgente non prevista', 'importo_sforo' => 122000, 'strategia_rientro' => 'conguaglio_fine_anno', 'fondo_patrimoniale_id' => null,
    ]], false);

    // È quello che la pagina legge per dire «il piano verrebbe a € 0,00» prima di salvare: tutte le voci spente, nessuna con qualcosa da mettere a rate.
    $selettore = snpSelettore($this, $r);
    expect($selettore)->toHaveCount(1)->and($selettore[0]['disabled'])->toBeTrue()->and($selettore[0]['residuo'])->toEqual(0);

    // Come nasce oggi a zero: la pagina manda le voci accese (nessuna), e il piano «include tutto» prende la voce esaurita.
    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Piano a zero', 'metodo_distribuzione' => 'rata_zero', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'data_delibera_assemblea' => '2026-10-15', 'capitoli_ids' => [], 'capitoli_config' => [], 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();
    expect(snpPerPersona($r, PianoRate::where('nome', 'Piano a zero')->sole()))->toBe(['ugo' => 0, 'bice' => 0]);
});

// --- B: la riga «Fuori preventivo» -------------------------------------------------------------------------------------------------------------

it('B, 686 (d) — il carrello offre la fattura «Fuori preventivo» sulla sua gestione, e non sull\'ordinaria', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r));

    expect(collect(snpCarrello($this, $r))->pluck('id')->all())->toBe([$f->id])
        ->and(snpCarrello($this, $r, $r['s']->gestioni['Ordinaria 2026']['g']->id))->toBe([]);
});

it('B, 686 (d) — un piano dell\'ordinaria non mette a rate una fattura della straordinaria, e il messaggio dice quale gestione scegliere', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r));

    $resp = $this->actingAs($r['s']->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['s']->gestioni['Ordinaria 2026']['g']->id, 'nome' => 'Sulla gestione sbagliata', 'metodo_distribuzione' => 'tutte_rate',
        'numero_rate' => 3, 'giorno_scadenza' => 5, 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/10/2026',
        'data_delibera_assemblea' => '2026-10-15', 'fatture_config' => [['id' => $f->id, 'importo' => '1.220,00']],
    ]);
    $resp->assertUnprocessable();
    expect($resp->json('errors')['fatture_config.0.id'])->toBe(['La fattura n. FT-ROB è della gestione «Spesa fuori bilancio»: si mette a rate in un piano di quella gestione. Scegli «Spesa fuori bilancio» come gestione di riferimento.'])
        ->and(PianoRate::count())->toBe(0);
});

it('B — controprova: sulla sua gestione il piano «Spese fuori preventivo» nasce e dà € 732,00 e € 488,00', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r));

    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Riparazione a rate', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/10/2026', 'data_delibera_assemblea' => '2026-10-15',
        'fatture_config' => [['id' => $f->id, 'importo' => '1.220,00']], 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();

    expect(snpPerPersona($r, PianoRate::where('nome', 'Riparazione a rate')->sole()))->toBe(['ugo' => 73200, 'bice' => 48800]);
});

it('B1, 687 (2) — «Modifica» della scadenza con il corpo della pagina: la riga resta «Fuori preventivo» e la fattura resta nel carrello', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false);
    expect(snpCarrello($this, $r))->toHaveCount(1);

    $this->actingAs($r['s']->admin)->put(route('admin.gestionale.fatture.update', [$r['s']->c, $f]), snpCorpoModifica($f))->assertSessionHasNoErrors();

    expect(DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->pluck('is_sopravvenienza')->map(fn ($v) => (bool) $v)->all())->toBe([true])
        ->and(collect(snpCarrello($this, $r))->pluck('id')->all())->toBe([$f->id])
        ->and($f->fresh()->data_scadenza->format('Y-m-d'))->toBe('2026-11-30');
});

it('B1, 687 (2) — anche il corpo di prima (il segno spento, una pagina rimasta aperta) non toglie il segno: il servizio lo tiene sulla stessa voce', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false);

    $this->actingAs($r['s']->admin)->put(route('admin.gestionale.fatture.update', [$r['s']->c, $f]), snpCorpoModifica($f, [], fn () => ['is_sopravvenienza' => false]))
        ->assertSessionHasNoErrors();

    expect(DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->pluck('is_sopravvenienza')->map(fn ($v) => (bool) $v)->all())->toBe([true])
        ->and(snpCarrello($this, $r))->toHaveCount(1);
});

it('B1 — il segno «Fuori preventivo» non si accende in modifica su una riga che non lo aveva', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false);

    $this->actingAs($r['s']->admin)->put(route('admin.gestionale.fatture.update', [$r['s']->c, $f]), snpCorpoModifica($f, [], fn () => ['is_sopravvenienza' => true]))
        ->assertSessionHasErrors(['righe.0.is_sopravvenienza' => 'Una riga «Fuori preventivo» non si aggiunge modificando la fattura: nasce con la registrazione, che crea la sua voce e il suo riparto. Togli il segno da questa riga, oppure storna la fattura e registrala di nuovo con la riga «Fuori preventivo».']);
    expect(DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->value('is_sopravvenienza'))->toBeFalsy();
});

it('B2, 686 (a) — la «Fuori preventivo» registrata nell\'altro esercizio della gestione: ciò che il carrello non mostra dice quante e quale esercizio', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $e27 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    // Come fa `GestioneController@update`: salvare la gestione la lega all'esercizio aperto.
    DB::table('esercizio_gestione')->insert(['esercizio_id' => $e27->id, 'gestione_id' => $r['g']->id, 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
    snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false, $e27->id);

    expect(snpCarrello($this, $r))->toBe([])
        ->and(snpCarrello($this, $r, null, $e27->id))->toHaveCount(1)
        ->and(snpFuori($this, $r)['altri_esercizi'])->toBe([['esercizio_id' => $e27->id, 'esercizio' => 'Esercizio 2027', 'quante' => 1]]);
});

it('B3, 686 (a) — la «Fuori preventivo» già per intero in un altro piano: ciò che il carrello non mostra la nomina con il nome del piano', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r));
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Primo tentativo', 'metodo_distribuzione' => 'rata_zero', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/10/2026', 'data_delibera_assemblea' => '2026-10-15',
        'fatture_config' => [['id' => $f->id, 'importo' => '1.220,00']], 'genera_subito' => 0,
    ])->assertSessionHasNoErrors();

    expect(snpCarrello($this, $r))->toBe([])
        ->and(snpFuori($this, $r)['gia_nei_piani'])->toBe(['totale' => 1, 'fatture' => [['id' => $f->id, 'fornitore' => 'Idraulica Srl', 'numero_documento' => 'FT-ROB',
            'piani' => ['Primo tentativo']]]]);
});

it('B4, 687 (1) — la pagina di modifica parte dalla gestione della fattura, e le voci dicono la loro gestione', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false);

    $this->actingAs($r['s']->admin)->get(route('admin.gestionale.fatture.edit', [$r['s']->c, $f]))->assertOk()
        ->assertInertia(fn ($p) => $p->where('fattura.gestione_id', $r['g']->id)
            ->where('conti', fn ($conti) => collect($conti)->firstWhere('id', $voce->id)['gestione_id'] === $r['g']->id));
});

it('B4, 687 (1) — «Modifica» della scadenza con il corpo della pagina: la scrittura resta sulla straordinaria', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false);
    expect(snpGestioniDelleScritture($r))->toBe([$r['g']->id]);

    $this->actingAs($r['s']->admin)->put(route('admin.gestionale.fatture.update', [$r['s']->c, $f]), snpCorpoModifica($f))->assertSessionHasNoErrors();

    expect(snpGestioniDelleScritture($r))->toBe([$r['g']->id])->and($f->fresh()->gestione_id)->toBe($r['g']->id);
});

it('B4, 687 (1) — l\'ordinaria con la voce della straordinaria (il corpo che la pagina mandava) si rifiuta, e la scrittura non si sposta', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false);

    $this->actingAs($r['s']->admin)->put(route('admin.gestionale.fatture.update', [$r['s']->c, $f]),
        snpCorpoModifica($f, ['gestione_id' => $r['s']->gestioni['Ordinaria 2026']['g']->id]))
        ->assertSessionHasErrors(['righe.0.conto_id' => 'La voce «Riparazione colonna» è della gestione «Spesa fuori bilancio», e la fattura è su «Ordinaria 2026»: scegli una voce di «Ordinaria 2026», oppure la gestione «Spesa fuori bilancio».']);

    expect(snpGestioniDelleScritture($r))->toBe([$r['g']->id])->and($f->fresh()->data_scadenza->format('Y-m-d'))->toBe('2026-11-20');
});

it('B5 — controprova: con la gestione straordinaria nel campo «Gestione» la scrittura resta lì, come prima', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false);

    $this->actingAs($r['s']->admin)->put(route('admin.gestionale.fatture.update', [$r['s']->c, $f]), snpCorpoModifica($f, ['gestione_id' => $r['g']->id]))->assertSessionHasNoErrors();

    expect(snpGestioniDelleScritture($r))->toBe([$r['g']->id]);
});

// --- 687 (3): «Finanzia spesa» della Dashboard -------------------------------------------------------------------------------------------------

it('687 (3) — «Finanzia spesa» della Dashboard porta la gestione della fattura, non la prima attiva dell\'esercizio', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r));

    $scoperte = collect($this->actingAs($r['s']->admin)->get(route('admin.gestionale.index', $r['s']->c->id))->assertOk()->viewData('page')['props']['fattureScoperte'])->keyBy('id');

    expect($scoperte->has($f->id))->toBeTrue('il cruscotto non mostra la fattura: lo scenario non è quello che credo')
        ->and($scoperte[$f->id]['gestione_id'])->toBe($r['g']->id)
        ->and($r['g']->id)->not->toBe($r['s']->gestioni['Ordinaria 2026']['g']->id);
});

// --- Fase 1-bis della beta.12 --------------------------------------------------------------------------------------------------------------------

/*
 * N4 della terza prova: una fattura della gestione giusta ma sulle voci del preventivo, senza righe «Fuori preventivo», mandata in un piano «Spese fuori
 * preventivo» (una pagina rimasta aperta, o una richiesta fatta a mano: il carrello non la offre). Con il codice di prima passava la validazione e la
 * generazione si fermava con il messaggio tecnico («righe imprevisto/ad personam», «copertura di tipo 'sopravvenienza'»), quello che la Coda 686 (b) voleva
 * togliere. Che cosa non copre: la pagina, che non la offre (`PianiRateNewSpesaFuoriPreventivo.test.ts`).
 */
it('N4 — una fattura sulla voce del preventivo in un piano «Spese fuori preventivo» si rifiuta con una frase semplice che dice l\'altra scheda; niente piano', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FS-1');

    $resp = $this->actingAs($r['s']->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Colonna a rate', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/10/2026', 'data_delibera_assemblea' => '2026-10-15',
        'fatture_config' => [['id' => $f->id, 'importo' => '1.220,00']], 'genera_subito' => 1,
    ]);

    $resp->assertUnprocessable();
    expect($resp->json('errors')['fatture_config.0.id'] ?? null)->toBe(['La fattura n. FS-1 è sulla voce «Riparazione colonna», senza righe «Fuori preventivo»: '
        . 'si mette a rate con «Dalle voci del preventivo», non con «Spese fuori preventivo». Toglila dal carrello.'])
        ->and(PianoRate::count())->toBe(0);
});

it('N4 — controprova: una fattura addebitata a una sola unità (una riga dell\'unità, senza «Fuori preventivo») passa la validazione come la offre il carrello', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FS-UNITA');
    DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->update(['immobile_id' => $r['s']->unita['A']->id]);
    expect(collect(snpCarrello($this, $r))->pluck('id')->all())->toBe([$f->id]);

    $resp = $this->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Colonna a rate', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/10/2026', 'data_delibera_assemblea' => '2026-10-15',
        'fatture_config' => [['id' => $f->id, 'importo' => '1.220,00']], 'genera_subito' => 0,
    ]);

    $resp->assertSessionHasNoErrors();
    expect(PianoRate::where('nome', 'Colonna a rate')->exists())->toBeTrue();
});

// P3 della lente P: le prove che uccidono le mutazioni sopravvissute del carrello (M-C3 della lente; C8 nuova).

it('P3 (M-C3) — una fattura «Fuori preventivo» già per intero in un piano ancora in bozza non torna nel carrello', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r));
    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Riparazione a rate', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/10/2026', 'data_delibera_assemblea' => '2026-10-15',
        'fatture_config' => [['id' => $f->id, 'importo' => '1.220,00']],
    ])->assertSessionHasNoErrors();
    // Il piano nasce approvato con la delibera; lo si riporta in bozza (lo stato di un piano importato, o non ancora deliberato).
    DB::table('piani_rate')->where('nome', 'Riparazione a rate')->update(['stato' => 'bozza']);

    // Contando i soli piani approvati la fattura tornerebbe nel carrello con € 1.220,00 da mettere a rate: un secondo piano la chiederebbe di nuovo.
    expect(snpCarrello($this, $r))->toBe([]);
});

it('P3 (C8) — una fattura pregressa entra nel carrello solo con una copertura per sopravvenienza: con il fondo di riserva o la rata 0 no', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $pregressa = function (string $numero, string $copertura) use ($r, $s, $voce): int {
        $id = DB::table('fatture_passive')->insertGetId([
            'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'fornitore_id' => $r['fornitoreId'], 'tipo_documento' => 'fattura',
            'numero_documento' => $numero, 'data_documento' => '2025-12-10', 'data_scadenza' => '2026-01-10', 'is_pregresso' => true, 'importo_imponibile' => 50000,
            'importo_iva' => 11000, 'netto_a_pagare' => 61000, 'totale_documento' => 61000, 'stato_approvazione' => 'approvata', 'stato_pagamento' => 'aperta',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fattura_coperture')->insert(['fattura_passiva_id' => $id, 'tipo_copertura' => $copertura, 'importo' => 61000,
            'conto_id' => $copertura === 'sopravvenienza' ? $voce->id : null, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $sopravvenienza = $pregressa('PREG-SOPR', 'sopravvenienza');
    $pregressa('PREG-FONDO', 'fondo_riserva');
    $pregressa('PREG-RATA0', 'rata_0');

    // Senza la regola della copertura entrerebbero tutte e tre, e il piano metterebbe a rate anche quelle già coperte dal fondo o dalla rata 0.
    expect(collect(snpCarrello($this, $r))->pluck('id')->all())->toBe([$sopravvenienza]);
});
