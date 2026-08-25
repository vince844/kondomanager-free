<?php

/**
 * La ritenuta d'acconto registrata su un pagamento fornitore deve corrispondere alle
 * fatture che quel pagamento sta pagando.
 *
 * Oggi non è così. `importo_ritenuta_cents` è validato come `nullable|integer|min:0`
 * (`StorePagamentoFornitoreRequest:81`, `UpdatePagamentoFornitoreRequest:79`) e il servizio
 * lo prende **così com'è** se diverso da zero (`PagamentoFornitoreService:305` in
 * registrazione, `:474` in aggiornamento). Nessun confronto con le allocazioni.
 *
 * Finché quell'importo era solo uno snapshot per la CU era un fastidio. Con il modulo F24
 * diventa **la cifra che si versa all'Erario**: è il difetto §8 punto 7 del design, ed è il
 * prerequisito del modulo, non un contorno.
 *
 * Due buchi distinti, che i test separano:
 *
 *  1. **In registrazione** un valore qualsiasi viene accettato. Il flusso reale è salvo per
 *     un caso fortunato — `PagamentoNew.vue` quel campo non lo manda affatto, quindi il
 *     server calcola sempre lui il pro-quota — ma l'API lo accetta da chiunque.
 *  2. **In aggiornamento** il campo omesso diventa `0` (`:474`, e la Request normalizza
 *     esplicitamente `null → 0` a `:121-122`): modificare un pagamento senza rimandare la
 *     ritenuta la **azzera**. Anche qui il flusso reale è salvo solo perché
 *     `PagamentoEdit.vue:113` la rimanda; nulla lo garantisce.
 *
 * La regola di dominio: le allocazioni alle fatture sono **immutabili in modifica** — lo
 * dice la schermata stessa — quindi la ritenuta non è un campo libero. È una funzione delle
 * fatture pagate e di quanto se ne paga. Il server la calcola; un valore in ingresso che
 * non coincide è un errore, non un override.
 */

use App\Exceptions\Pagamenti\RitenutaIncoerenteException;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\PagamentoFornitoreService;

require_once __DIR__.'/GestionaleTestHelpers.php';

/** Contesto con un fornitore davvero soggetto a ritenuta 4%. */
function ctxConRitenuta(): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100]);
    $ctx[3]->refresh();

    return $ctx;
}

/** Fattura da 1.000 € + IVA 22% con ritenuta 4% → 40,00 € di ritenuta, 1.180,00 € netti. */
function fatturaConRitenuta(array $ctx)
{
    return registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);
}

// ════════════════════════════════════════════════════════════════════════════
// Il comportamento che deve restare: il server calcola da sé
// ════════════════════════════════════════════════════════════════════════════

test('senza il campo, la ritenuta è calcolata pro-quota dalle fatture pagate', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);

    expect((int) $fattura->importo_ritenuta)->toBeGreaterThan(0);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));

    expect((int) $pagamento->importo_ritenuta)->toEqual((int) $fattura->importo_ritenuta);
});

test('pagando metà fattura la ritenuta scende a metà', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);
    $meta = intdiv((int) $fattura->netto_a_pagare, 2);

    $dati = datiPagamento($ctx, $fattura);
    $dati['allocazioni'][0]['importo_allocato_cents'] = $meta;

    $pagamento = (new PagamentoFornitoreService)->registraPagamento($dati);

    $atteso = (int) round($fattura->importo_ritenuta * ($meta / $fattura->netto_a_pagare));
    expect((int) $pagamento->importo_ritenuta)->toEqual($atteso);
});

test('un valore coerente con le allocazioni viene accettato', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['importo_ritenuta_cents' => (int) $fattura->importo_ritenuta])
    );

    expect((int) $pagamento->importo_ritenuta)->toEqual((int) $fattura->importo_ritenuta);
});

// ════════════════════════════════════════════════════════════════════════════
// Registrazione: un importo inventato non deve passare
// ════════════════════════════════════════════════════════════════════════════

test('in registrazione un importo che non corrisponde alle fatture viene rifiutato', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['importo_ritenuta_cents' => 999_99])
    ))->toThrow(RitenutaIncoerenteException::class);

    expect(PagamentoFornitore::count())->toEqual(0);
});

test('anche un importo plausibile ma sbagliato di un centesimo viene rifiutato', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['importo_ritenuta_cents' => (int) $fattura->importo_ritenuta + 1])
    ))->toThrow(RitenutaIncoerenteException::class);
});

test('il messaggio dice quale importo era atteso e quale è arrivato', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);

    try {
        (new PagamentoFornitoreService)->registraPagamento(
            datiPagamento($ctx, $fattura, ['importo_ritenuta_cents' => 12_345])
        );
        $this->fail('Doveva sollevare RitenutaIncoerenteException.');
    } catch (RitenutaIncoerenteException $e) {
        expect($e->getMessage())->toContain('123,45')
            ->and($e->getMessage())->toContain('40,00');
    }
});

// ════════════════════════════════════════════════════════════════════════════
// Aggiornamento: il campo omesso non deve azzerare niente
// ════════════════════════════════════════════════════════════════════════════

test('aggiornare senza rimandare la ritenuta non la azzera', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);
    $service = new PagamentoFornitoreService;

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));
    $ritenutaOriginale = (int) $pagamento->importo_ritenuta;

    expect($ritenutaOriginale)->toBeGreaterThan(0);

    // Un aggiornamento che cambia solo una nota, senza toccare gli importi.
    $service->aggiornaPagamento($pagamento, [
        'data_pagamento' => $pagamento->data_pagamento->format('Y-m-d'),
        'metodo_pagamento' => $pagamento->metodo_pagamento->value,
        'conto_corrente_id' => $pagamento->conto_corrente_id,
        'importo_lordo_cents' => (int) $pagamento->importo_lordo,
        'importo_netto_cents' => (int) $pagamento->importo_netto,
        'importo_commissioni_cents' => 0,
        'note_override' => 'Solo una nota',
        'iban_confermato_manualmente' => true,
        'allow_overdraft' => true,
    ]);

    expect((int) $pagamento->fresh()->importo_ritenuta)->toEqual($ritenutaOriginale);
});

test('in aggiornamento un importo diverso da quello registrato viene rifiutato', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);
    $service = new PagamentoFornitoreService;

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));

    // Le allocazioni sono immutabili in modifica: la ritenuta non può cambiare.
    expect(fn () => $service->aggiornaPagamento($pagamento, [
        'data_pagamento' => $pagamento->data_pagamento->format('Y-m-d'),
        'metodo_pagamento' => $pagamento->metodo_pagamento->value,
        'conto_corrente_id' => $pagamento->conto_corrente_id,
        'importo_lordo_cents' => (int) $pagamento->importo_lordo,
        'importo_netto_cents' => (int) $pagamento->importo_netto,
        'importo_commissioni_cents' => 0,
        'importo_ritenuta_cents' => 1,
        'iban_confermato_manualmente' => true,
        'allow_overdraft' => true,
    ]))->toThrow(RitenutaIncoerenteException::class);
});

test('rimandare la stessa ritenuta in aggiornamento è legittimo', function () {
    $ctx = ctxConRitenuta();
    $fattura = fatturaConRitenuta($ctx);
    $service = new PagamentoFornitoreService;

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));
    $ritenutaOriginale = (int) $pagamento->importo_ritenuta;

    $service->aggiornaPagamento($pagamento, [
        'data_pagamento' => $pagamento->data_pagamento->format('Y-m-d'),
        'metodo_pagamento' => $pagamento->metodo_pagamento->value,
        'conto_corrente_id' => $pagamento->conto_corrente_id,
        'importo_lordo_cents' => (int) $pagamento->importo_lordo,
        'importo_netto_cents' => (int) $pagamento->importo_netto,
        'importo_commissioni_cents' => 0,
        'importo_ritenuta_cents' => $ritenutaOriginale,
        'iban_confermato_manualmente' => true,
        'allow_overdraft' => true,
    ]);

    expect((int) $pagamento->fresh()->importo_ritenuta)->toEqual($ritenutaOriginale);
});

// ════════════════════════════════════════════════════════════════════════════
// Fornitore senza ritenuta: niente deve cambiare
// ════════════════════════════════════════════════════════════════════════════

test('su un fornitore non soggetto a ritenuta il pagamento resta a zero', function () {
    $ctx = setupPagamentiService();   // soggetto_ritenuta = false
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));

    expect((int) $pagamento->importo_ritenuta)->toEqual(0);
});

test('su un fornitore senza ritenuta un importo inventato viene comunque rifiutato', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['importo_ritenuta_cents' => 5_000])
    ))->toThrow(RitenutaIncoerenteException::class);
});
