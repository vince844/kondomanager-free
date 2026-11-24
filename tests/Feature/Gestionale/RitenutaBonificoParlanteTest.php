<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il bonifico parlante esclude la ritenuta del condominio.
 *
 * È una regola dell'Agenzia, e il testo è esplicito: sulle somme pagate con bonifico bancario
 * o postale per avvalersi delle detrazioni per ristrutturazione edilizia o riqualificazione
 * energetica, la ritenuta la opera **la banca** — e il condominio non applica la propria, per
 * non produrre un doppio prelievo sullo stesso corrispettivo.
 *
 * ## Dalla Coda 754 (2) (decisione 696, 1.12.0-beta.14): la ritenuta si decide nella fattura
 *
 * Fino alla beta.13 la ritenuta si azzerava **al pagamento**: la fattura la esponeva, e la spunta
 * «Bonifico Parlante» metteva a zero quella del pagamento. Ma la fattura con la ritenuta la scrive
 * su «Debiti v/Erario» già alla registrazione e chiede al fornitore il netto: il pagamento usciva
 * del netto (€ 1.180,00 su € 1.220,00, il lordo rifiutato come eccedenza) e la ritenuta restava
 * sull'Erario senza che nessun F24 la versasse, perché le deleghe leggono la ritenuta dei pagamenti.
 *
 * Con la decisione la ritenuta si decide in un posto solo, la fattura. La fattura che si pagherà
 * con il bonifico parlante si registra **senza** la ritenuta, con il motivo
 * `MotivoEsclusioneRitenuta::BONIFICO_PARLANTE`; il pagamento con la spunta di una fattura
 * registrata con la ritenuta **si ferma** (`BonificoParlanteConRitenutaException`) e dice di
 * togliere prima la ritenuta dalla fattura. Non si può più pagare la stessa fattura con la ritenuta
 * metà con un bonifico ordinario e metà con il bonifico parlante. Le prove di questo file che
 * fissavano la regola di prima ora fissano questa; le prove dalle rotte, con la catena fino all'F24
 * e il comando dei dati già scritti, sono in `Beta14BonificoParlanteConRitenutaTest.php`.
 *
 * Il motivo dell'esclusione sta nella fattura; sul pagamento **non si persiste**, si deriva. Un
 * pagamento con `bonifico_parlante = true` e ritenuta a zero *è* un'esclusione da bonifico parlante,
 * e una colonna in più direbbe la stessa cosa con la possibilità di divergere.
 */

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Exceptions\Pagamenti\BonificoParlanteConRitenutaException;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\PlafondRitenuteService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

/** Contesto con un fornitore soggetto a ritenuta 4%: senza, il test non proverebbe niente. */
function ctxBonificoParlante(): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100]);
    $ctx[3]->refresh();

    return $ctx;
}

/** I campi che accompagnano sempre un bonifico parlante: il tipo è obbligatorio con il flag. */
function datiBonificoParlante(array $extra = []): array
{
    return array_merge([
        'bonifico_parlante' => true,
        'tipo_detrazione' => 'ristrutturazione',
    ], $extra);
}

/** La fattura che si pagherà con il bonifico parlante, registrata come vuole la decisione 696: senza ritenuta, con il motivo. */
function fatturaConMotivoBonificoParlante(array $ctx): FatturaPassiva
{
    return registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'bonifico_parlante'], 'competenza' => null, 'override_budget' => null],
    ]);
}

/**
 * Un pagamento come lo scriveva la beta.13: il bonifico parlante sulla fattura con la ritenuta, la ritenuta del pagamento a zero,
 * uscito il netto. Il servizio oggi lo rifiuta, quindi si registra senza la spunta e la spunta si scrive nel database.
 */
function pagamentoConBonificoParlanteDiPrima(array $ctx, FatturaPassiva $fattura): PagamentoFornitore
{
    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));
    DB::table('pagamenti_fornitori')->where('id', $pagamento->id)->update([
        'bonifico_parlante' => true,
        'tipo_detrazione' => 'ristrutturazione',
        'importo_ritenuta' => 0,
        'importo_lordo' => (int) $pagamento->importo_netto,
    ]);

    return $pagamento->fresh();
}

// ════════════════════════════════════════════════════════════════════════════
// Raggiungibilità: il caso esiste davvero, e si ferma
// ════════════════════════════════════════════════════════════════════════════

/**
 * Prima di credere a un difetto, verificare che ci si arrivi — la lezione della beta.37.
 * Prima della beta.14 questa prova diceva che la spunta passava su una fattura con la ritenuta;
 * dalla decisione 696 il pagamento si ferma, e non scrive niente.
 */
test('il bonifico parlante su una fattura che porta ritenuta si ferma e non scrive niente', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    expect((int) $fattura->importo_ritenuta)->toBeGreaterThan(0);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, datiBonificoParlante())))
        ->toThrow(BonificoParlanteConRitenutaException::class, 'togli prima la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante»)');

    expect(PagamentoFornitore::count())->toEqual(0);
});

// ════════════════════════════════════════════════════════════════════════════
// L'esclusione: la fattura registrata con il motivo «Bonifico parlante»
// ════════════════════════════════════════════════════════════════════════════

test('con il bonifico parlante il condominio non trattiene la ritenuta', function () {
    $ctx = ctxBonificoParlante();
    $fattura = fatturaConMotivoBonificoParlante($ctx);

    // Il fornitore è soggetto: a togliere la ritenuta è il motivo della fattura.
    expect($ctx[3]->soggetto_ritenuta)->toBeTrue()
        ->and((int) $fattura->importo_ritenuta)->toEqual(0);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );

    expect($pagamento->bonifico_parlante)->toBeTrue()
        ->and($pagamento->tipo_detrazione->value)->toEqual('ristrutturazione')
        ->and((int) $pagamento->importo_ritenuta)->toEqual(0);
});

/**
 * La conseguenza che si vede sul conto corrente: al fornitore esce l'importo **pieno**.
 * È il punto in cui il difetto costava denaro vero — non un numero sbagliato in un report,
 * ma il 4% trattenuto a un fornitore a cui non andava trattenuto.
 */
test('al fornitore esce l importo pieno, senza trattenuta', function () {
    $ctx = ctxBonificoParlante();
    $fattura = fatturaConMotivoBonificoParlante($ctx);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );

    expect((int) $pagamento->importo_netto)->toEqual((int) $pagamento->importo_lordo)
        ->and((int) $pagamento->importo_netto)->toEqual(122000);
});

/**
 * Prima della beta.14: «la fattura conserva la ritenuta che aveva calcolato» mentre il pagamento la
 * azzerava, ed era il difetto. Ora il pagamento rifiutato lascia la fattura com'era, con la sua
 * ritenuta su «Debiti v/Erario» e il debito al netto, finché l'amministratore non la modifica.
 */
test('la fattura con la ritenuta resta com era quando il bonifico parlante si ferma', function () {
    [$condominio] = $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);
    $ritenutaFattura = (int) $fattura->importo_ritenuta;

    try {
        (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, datiBonificoParlante()));
    } catch (BonificoParlanteConRitenutaException) {
    }

    $erario = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->value('id');
    $saldoErario = (int) DB::table('righe_scritture')->where('conto_contabile_id', $erario)->where('tipo_riga', 'avere')->sum('importo')
        - (int) DB::table('righe_scritture')->where('conto_contabile_id', $erario)->where('tipo_riga', 'dare')->sum('importo');

    expect(PagamentoFornitore::count())->toEqual(0)
        ->and($fattura->fresh()->stato_pagamento)->toBe(\App\Enums\StatoPagamentoFattura::APERTA)
        ->and((int) $fattura->fresh()->importo_ritenuta)->toEqual($ritenutaFattura)
        ->and((int) $fattura->fresh()->netto_a_pagare)->toEqual(122000 - $ritenutaFattura)
        ->and($saldoErario)->toEqual($ritenutaFattura);
});

// ════════════════════════════════════════════════════════════════════════════
// L'effetto a valle: niente F24
// ════════════════════════════════════════════════════════════════════════════

/**
 * Il test che conta più di tutti. Se la ritenuta finisse comunque nello scadenzario, il
 * condominio verserebbe all'Erario un importo che la banca ha già versato: **doppio
 * prelievo**, e un F24 da stornare.
 */
test('la ritenuta esclusa non entra nello scadenzario F24', function () {
    [$condominio, $esercizio] = $ctx = ctxBonificoParlante();
    $fattura = fatturaConMotivoBonificoParlante($ctx);

    (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );

    $azione = new GeneraDelegheF24Action(new PlafondRitenuteService(new CalendarioFiscaleService));

    expect($azione->ritenuteDaVersare($condominio, $esercizio->id))->toBeEmpty();
});

// ════════════════════════════════════════════════════════════════════════════
// La guardia della beta.38 non deve andare di traverso
// ════════════════════════════════════════════════════════════════════════════

/**
 * `verificaCoerenzaRitenuta()` rifiuta un importo dichiarato diverso da quello dovuto, senza
 * tolleranza. Prima della beta.14 questa prova diceva che, con la spunta, il valore stantio della
 * fattura non bloccava il salvataggio e vinceva lo zero del server. Dalla decisione 696 quel
 * pagamento si ferma prima, e con la frase del bonifico parlante: non con quella della ritenuta
 * incoerente, che all'amministratore non direbbe che cosa fare.
 */
test('un importo dichiarato stantio non cambia il rifiuto: si ferma con la frase del bonifico parlante', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante([
            // Il valore che il form aveva in mano prima della spunta.
            'importo_ritenuta_cents' => (int) $fattura->importo_ritenuta,
        ]))
    ))->toThrow(BonificoParlanteConRitenutaException::class);

    expect(PagamentoFornitore::count())->toEqual(0);
});

// ════════════════════════════════════════════════════════════════════════════
// In modifica il flag cambia, e la ritenuta deve seguirlo nei due versi
// ════════════════════════════════════════════════════════════════════════════

/** Un aggiornamento che tocca solo il bonifico parlante, lasciando gli importi dov'erano. */
function aggiornaSoloBonificoParlante(PagamentoFornitore $pagamento, bool $attivo): void
{
    (new PagamentoFornitoreService)->aggiornaPagamento($pagamento, [
        'data_pagamento' => $pagamento->data_pagamento->format('Y-m-d'),
        'metodo_pagamento' => $pagamento->metodo_pagamento->value,
        'conto_corrente_id' => $pagamento->conto_corrente_id,
        'importo_lordo_cents' => (int) $pagamento->importo_lordo,
        'importo_netto_cents' => (int) $pagamento->importo_netto,
        'importo_commissioni_cents' => 0,
        'bonifico_parlante' => $attivo,
        'tipo_detrazione' => $attivo ? 'ristrutturazione' : null,
        // Il form rimanda la ritenuta già salvata: è il valore stantìo che la guardia
        // vedrebbe, ed è esattamente il caso che deve non bloccare nulla.
        'importo_ritenuta_cents' => (int) $pagamento->importo_ritenuta,
        'iban_confermato_manualmente' => true,
        'allow_overdraft' => true,
    ]);
}

/** Prima della beta.14 la spunta messa in modifica azzerava la ritenuta; dalla decisione 696 si ferma, e il pagamento resta com'era. */
test('spuntando il bonifico parlante in modifica su una fattura con la ritenuta il pagamento si ferma', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));
    expect((int) $pagamento->importo_ritenuta)->toBeGreaterThan(0);

    expect(fn () => aggiornaSoloBonificoParlante($pagamento, true))->toThrow(BonificoParlanteConRitenutaException::class);

    expect((int) $pagamento->fresh()->importo_ritenuta)->toEqual((int) $fattura->importo_ritenuta)
        ->and($pagamento->fresh()->bonifico_parlante)->toBeFalse();
});

/** La controprova: sulla fattura registrata con il motivo la spunta messa in modifica si salva, e la ritenuta resta zero. */
test('spuntando il bonifico parlante in modifica su una fattura con il motivo la spunta si salva', function () {
    $ctx = ctxBonificoParlante();
    $fattura = fatturaConMotivoBonificoParlante($ctx);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));
    expect((int) $pagamento->importo_ritenuta)->toEqual(0);

    aggiornaSoloBonificoParlante($pagamento, true);

    expect($pagamento->fresh()->bonifico_parlante)->toBeTrue()
        ->and((int) $pagamento->fresh()->importo_ritenuta)->toEqual(0);
});

/**
 * Il verso opposto, che è quello che si dimentica. Tolta la spunta — per esempio perché era
 * stata messa per errore — la ritenuta non può restare a zero: il condominio smetterebbe di
 * trattenere senza che nessuno l'abbia deciso, e la cifra non tornerebbe mai nell'F24.
 *
 * Il valore salvato è zero, quindi non c'è niente da cui ripartire: va ricalcolato dalle
 * fatture, che in modifica sono immutabili e quindi danno lo stesso numero di partenza.
 * Dalla beta.14 è la via per correggere un pagamento scritto prima con la spunta su una fattura
 * con la ritenuta (quelli che elenca `kondomanager:verifica-bonifici-parlanti`).
 */
test('togliendo il bonifico parlante la ritenuta torna quella delle fatture', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = pagamentoConBonificoParlanteDiPrima($ctx, $fattura);
    expect((int) $pagamento->importo_ritenuta)->toEqual(0)
        ->and($pagamento->bonifico_parlante)->toBeTrue();

    aggiornaSoloBonificoParlante($pagamento, false);

    expect((int) $pagamento->fresh()->importo_ritenuta)->toEqual((int) $fattura->importo_ritenuta);
});

/**
 * E la controprova che nessun dato storico venga riscritto di sponda: un aggiornamento che
 * **non tocca** il flag lascia la ritenuta esattamente dov'era, senza ricalcolarla.
 */
test('un aggiornamento che non tocca il flag non ricalcola la ritenuta', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));
    $prima = (int) $pagamento->importo_ritenuta;

    aggiornaSoloBonificoParlante($pagamento, false);

    expect((int) $pagamento->fresh()->importo_ritenuta)->toEqual($prima);
});

/** Un pagamento scritto prima con la spunta si corregge negli altri campi senza essere rifiutato: la spunta non si mette adesso. */
test('un pagamento scritto prima con la spunta su una fattura con la ritenuta resta modificabile', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);
    $pagamento = pagamentoConBonificoParlanteDiPrima($ctx, $fattura);

    aggiornaSoloBonificoParlante($pagamento, true);

    expect($pagamento->fresh()->bonifico_parlante)->toBeTrue()
        ->and((int) $pagamento->fresh()->importo_ritenuta)->toEqual(0);
});

// ════════════════════════════════════════════════════════════════════════════
// Controprova: senza il flag non cambia niente
// ════════════════════════════════════════════════════════════════════════════

test('senza bonifico parlante la ritenuta resta quella della fattura', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));

    expect((int) $pagamento->importo_ritenuta)->toEqual((int) $fattura->importo_ritenuta)
        ->and((int) $pagamento->importo_ritenuta)->toBeGreaterThan(0);
});

test('un bonifico parlante su un fornitore senza ritenuta non cambia nulla', function () {
    $ctx = setupPagamentiService();   // fornitore NON soggetto a ritenuta
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );

    expect((int) $pagamento->importo_ritenuta)->toEqual(0)
        ->and(PagamentoFornitore::count())->toEqual(1);
});
