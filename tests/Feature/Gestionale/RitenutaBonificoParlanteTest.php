<?php

/**
 * Il bonifico parlante esclude la ritenuta del condominio.
 *
 * È una regola dell'Agenzia, e il testo è esplicito: sulle somme pagate con bonifico bancario
 * o postale per avvalersi delle detrazioni per ristrutturazione edilizia o riqualificazione
 * energetica, la ritenuta la opera **la banca** — e il condominio non applica la propria, per
 * non produrre un doppio prelievo sullo stesso corrispettivo.
 *
 * Nel gestionale il presupposto c'era tutto: `pagamenti_fornitori.bonifico_parlante` esiste
 * ed è validato, `tipo_detrazione` porta i cinque casi con i riferimenti normativi per la
 * causale, e l'enum `MotivoEsclusioneRitenuta::BONIFICO_PARLANTE` era **dichiarato**. Solo che
 * `calcolaRitenutaProQuota()` quel dato non lo leggeva: la ritenuta continuava a essere
 * trattenuta al fornitore e a finire nell'F24.
 *
 * ## Perché la ritenuta si azzera al PAGAMENTO e non alla fattura
 *
 * La fattura non sbaglia a esporre la ritenuta: quando viene registrata nessuno sa ancora come
 * verrà pagata. È il bonifico parlante a rimuoverla, ed è una scelta che si fa al momento del
 * pagamento — coerente con il principio su cui è costruito tutto il modulo F24, che il fatto
 * generatore è il pagamento e non la registrazione. Tant'è che la stessa fattura si può pagare
 * in due volte, un acconto con bonifico ordinario e il saldo con bonifico parlante.
 *
 * Il motivo dell'esclusione **non si persiste**: si deriva. Un pagamento con
 * `bonifico_parlante = true` e ritenuta a zero *è* un'esclusione da bonifico parlante, e una
 * colonna in più direbbe la stessa cosa con la possibilità di divergere.
 */

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\PlafondRitenuteService;

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

// ════════════════════════════════════════════════════════════════════════════
// Raggiungibilità: il caso esiste davvero
// ════════════════════════════════════════════════════════════════════════════

/**
 * Prima di credere a un difetto, verificare che ci si arrivi — la lezione della beta.37.
 * Se una guardia vietasse di spuntare il bonifico parlante su una fattura con ritenuta, il
 * doppio prelievo sarebbe impossibile e questi test non servirebbero a niente.
 */
test('il bonifico parlante è davvero spuntabile su una fattura che porta ritenuta', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    expect((int) $fattura->importo_ritenuta)->toBeGreaterThan(0);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );

    expect($pagamento->bonifico_parlante)->toBeTrue()
        ->and($pagamento->tipo_detrazione->value)->toEqual('ristrutturazione');
});

// ════════════════════════════════════════════════════════════════════════════
// L'esclusione
// ════════════════════════════════════════════════════════════════════════════

test('con il bonifico parlante il condominio non trattiene la ritenuta', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );

    expect((int) $pagamento->importo_ritenuta)->toEqual(0);
});

/**
 * La conseguenza che si vede sul conto corrente: al fornitore esce l'importo **pieno**.
 * È il punto in cui il difetto costava denaro vero — non un numero sbagliato in un report,
 * ma il 4% trattenuto a un fornitore a cui non andava trattenuto.
 */
test('al fornitore esce l importo pieno, senza trattenuta', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );

    expect((int) $pagamento->importo_netto)->toEqual((int) $pagamento->importo_lordo);
});

/**
 * La fattura **conserva** la sua ritenuta: non era sbagliata, semplicemente non sapeva come
 * sarebbe stata pagata. Riscriverla significherebbe perdere il dato di partenza.
 */
test('la fattura conserva la ritenuta che aveva calcolato', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);
    $ritenutaFattura = (int) $fattura->importo_ritenuta;

    (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );

    expect((int) $fattura->fresh()->importo_ritenuta)->toEqual($ritenutaFattura);
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
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

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
 * tolleranza. È giusto così — ma la schermata di **modifica** rimanda il valore già salvato
 * (`PagamentoEdit.vue:113`), che è stato calcolato prima che si scegliesse il bonifico
 * parlante. Senza un'eccezione esplicita, la guardia bloccherebbe proprio il salvataggio
 * corretto, e l'amministratore vedrebbe un errore incomprensibile spuntando una casella.
 */
test('un importo dichiarato stantio non blocca il salvataggio quando c è il bonifico parlante', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante([
            // Il valore che il form aveva in mano prima della spunta.
            'importo_ritenuta_cents' => (int) $fattura->importo_ritenuta,
        ]))
    );

    // Vince il calcolo del server, non il valore dichiarato.
    expect((int) $pagamento->importo_ritenuta)->toEqual(0);
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

test('spuntando il bonifico parlante in modifica la ritenuta si azzera', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));
    expect((int) $pagamento->importo_ritenuta)->toBeGreaterThan(0);

    aggiornaSoloBonificoParlante($pagamento, true);

    expect((int) $pagamento->fresh()->importo_ritenuta)->toEqual(0);
});

/**
 * Il verso opposto, che è quello che si dimentica. Tolta la spunta — per esempio perché era
 * stata messa per errore — la ritenuta non può restare a zero: il condominio smetterebbe di
 * trattenere senza che nessuno l'abbia deciso, e la cifra non tornerebbe mai nell'F24.
 *
 * Il valore salvato è zero, quindi non c'è niente da cui ripartire: va ricalcolato dalle
 * fatture, che in modifica sono immutabili e quindi danno lo stesso numero di partenza.
 */
test('togliendo il bonifico parlante la ritenuta torna quella delle fatture', function () {
    $ctx = ctxBonificoParlante();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, datiBonificoParlante())
    );
    expect((int) $pagamento->importo_ritenuta)->toEqual(0);

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
