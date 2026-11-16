<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * La sentinella dell'IBAN nel pagamento a un fornitore, lato server.
 *
 * Il fornitore ha `iban_principale`. Un IBAN del pagamento diverso da quello in anagrafica, senza la conferma
 * dell'amministratore, è rifiutato con una frase che l'amministratore capisce; con la conferma passa. Il confronto
 * ignora spazi e maiuscole, da tutte e due le parti. Un fornitore senza IBAN in anagrafica non ha niente da confrontare.
 *
 * Non vede: il confronto in pagina (`PagamentoNew.vue`, `PagamentoEdit.vue`), che resta un avviso a video.
 */

use App\Exceptions\Pagamenti\IbanDiscrepanzaException;
use App\Services\Gestionale\PagamentoFornitoreService;

function pagamentoConIban(?string $ibanAnagrafica, string $ibanDelPagamento, bool $confermato): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update(['iban_principale' => $ibanAnagrafica]);
    $fattura = registraFatturaServiceTest($ctx);

    return [$ctx, $fattura, datiPagamento($ctx, $fattura, [
        'iban_beneficiario' => $ibanDelPagamento,
        'iban_confermato_manualmente' => $confermato,
    ])];
}

it('un IBAN diverso da quello in anagrafica, senza conferma, è rifiutato', function () {
    [, , $dati] = pagamentoConIban('IT60X0542811101000000123456', 'IT28W8000000292100645211208', false);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento($dati))
        ->toThrow(IbanDiscrepanzaException::class);
});

it('il rifiuto parla all\'amministratore: i due IBAN e che cosa fare, senza nomi di campi', function () {
    [, , $dati] = pagamentoConIban('IT60X0542811101000000123456', 'IT28W8000000292100645211208', false);

    try {
        (new PagamentoFornitoreService)->registraPagamento($dati);
        $this->fail('Il pagamento doveva essere rifiutato.');
    } catch (IbanDiscrepanzaException $e) {
        expect($e->getMessage())
            ->toContain('IT28W8000000292100645211208')
            ->toContain('IT60X0542811101000000123456')
            ->not->toContain('iban_confermato_manualmente')
            ->not->toContain('Impostare');
    }
});

it('un IBAN diverso con la conferma passa', function () {
    [, , $dati] = pagamentoConIban('IT60X0542811101000000123456', 'IT28W8000000292100645211208', true);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento($dati);

    expect($pagamento->iban_beneficiario)->toBe('IT28W8000000292100645211208');
});

it('un IBAN uguale passa, anche scritto con gli spazi o in minuscolo', function (string $scritto) {
    [, , $dati] = pagamentoConIban('IT60X0542811101000000123456', $scritto, false);

    expect((new PagamentoFornitoreService)->registraPagamento($dati)->id)->not->toBeNull();
})->with([
    'con gli spazi' => 'IT60 X054 2811 1010 0000 0123 456',
    'in minuscolo' => 'it60x0542811101000000123456',
    'tutte e due' => ' it60 x054 2811 1010 0000 0123 456 ',
]);

it('l\'IBAN in anagrafica scritto con gli spazi si confronta senza', function () {
    [, , $dati] = pagamentoConIban('it60 X054 2811 1010 0000 0123 456', 'IT60X0542811101000000123456', false);

    expect((new PagamentoFornitoreService)->registraPagamento($dati)->id)->not->toBeNull();
});

it('un fornitore senza IBAN in anagrafica non ha niente da confrontare', function () {
    [, , $dati] = pagamentoConIban(null, 'IT28W8000000292100645211208', false);

    expect((new PagamentoFornitoreService)->registraPagamento($dati)->id)->not->toBeNull();
});

it('la fotografia del pagamento ha l\'IBAN in anagrafica del fornitore', function () {
    [, , $dati] = pagamentoConIban('IT60X0542811101000000123456', 'IT28W8000000292100645211208', true);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento($dati);
    $fotografia = $pagamento->fresh();
    $colonna = collect($fotografia->getAttributes())->first(fn ($v) => is_string($v) && str_contains($v, 'schema_version'));

    expect(json_decode($colonna, true)['iban'] ?? null)->toBe('IT60X0542811101000000123456');
});

// ── La modifica di un pagamento passa dalla stessa sentinella ────────────────────────────────────────────

/** Un pagamento registrato con l'IBAN dato (confermato) e i dati per modificarlo: [pagamento, dati della modifica]. */
function pagamentoDaModificare(?string $ibanAnagrafica, string $ibanRegistrato): array
{
    [$ctx, $fattura, $dati] = pagamentoConIban($ibanAnagrafica, $ibanRegistrato, true);
    $pagamento = (new PagamentoFornitoreService)->registraPagamento($dati);

    return [$pagamento, [
        'conto_corrente_id' => $ctx[4],
        'data_pagamento' => $dati['data_pagamento'],
        'metodo_pagamento' => $dati['metodo_pagamento'],
        'importo_lordo_cents' => $pagamento->importo_lordo,
        'importo_netto_cents' => $pagamento->importo_netto,
        'allow_overdraft' => true,
    ]];
}

it('la modifica con un IBAN diverso da quello in anagrafica, senza conferma, è rifiutata e non cambia niente', function () {
    [$pagamento, $modifica] = pagamentoDaModificare('IT60X0542811101000000123456', 'IT60X0542811101000000123456');

    expect(fn () => (new PagamentoFornitoreService)->aggiornaPagamento($pagamento, $modifica + ['iban_beneficiario' => 'IT28W8000000292100645211208']))
        ->toThrow(IbanDiscrepanzaException::class, 'IT60X0542811101000000123456');
    expect($pagamento->fresh()->iban_beneficiario)->toBe('IT60X0542811101000000123456');
});

it('la modifica con un IBAN diverso e la conferma passa', function () {
    [$pagamento, $modifica] = pagamentoDaModificare('IT60X0542811101000000123456', 'IT60X0542811101000000123456');

    $nuovo = (new PagamentoFornitoreService)->aggiornaPagamento($pagamento, $modifica + ['iban_beneficiario' => 'IT28W8000000292100645211208', 'iban_confermato_manualmente' => true]);

    expect($nuovo->iban_beneficiario)->toBe('IT28W8000000292100645211208');
});

it('la modifica che non tocca l\'IBAN passa, anche se quello registrato era diverso dall\'anagrafica e confermato', function () {
    [$pagamento, $modifica] = pagamentoDaModificare('IT60X0542811101000000123456', 'IT28W8000000292100645211208');

    expect((new PagamentoFornitoreService)->aggiornaPagamento($pagamento, $modifica + ['iban_beneficiario' => 'IT28W8000000292100645211208'])->id)->toBe($pagamento->id)
        ->and((new PagamentoFornitoreService)->aggiornaPagamento($pagamento->fresh(), $modifica)->id)->toBe($pagamento->id);
});

it('la modifica con l\'IBAN dell\'anagrafica scritto con gli spazi o in minuscolo passa', function () {
    [$pagamento, $modifica] = pagamentoDaModificare('IT60X0542811101000000123456', 'IT28W8000000292100645211208');

    expect((new PagamentoFornitoreService)->aggiornaPagamento($pagamento, $modifica + ['iban_beneficiario' => 'it60 x054 2811 1010 0000 0123 456'])->iban_beneficiario)
        ->toBe('it60 x054 2811 1010 0000 0123 456');
});

it('lo spazio che non si spezza dentro l\'IBAN (copiato da un PDF o da una pagina web) non è una discrepanza', function () {
    $nbsp = "\u{00A0}";
    [, , $dati] = pagamentoConIban('IT60X0542811101000000123456', 'IT60'.$nbsp.'X054'.$nbsp.'2811101000000123456', false);
    expect((new PagamentoFornitoreService)->registraPagamento($dati)->id)->not->toBeNull();

    [, , $dati2] = pagamentoConIban('IT60'.$nbsp.'X0542811101000000123456', 'IT60X0542811101000000123456', false);
    expect((new PagamentoFornitoreService)->registraPagamento($dati2)->id)->not->toBeNull();
});

it('lo spazio di larghezza zero e il segno di inizio testo, che una copia porta, non sono una discrepanza: la pagina li toglie, e il server pure', function () {
    [, , $dati] = pagamentoConIban('IT60X0542811101000000123456', "\u{FEFF}IT60X054\u{200B}2811101000000123456", false);
    expect((new PagamentoFornitoreService)->registraPagamento($dati)->id)->not->toBeNull();
});

it('la modifica che rimanda lo stesso IBAN registrato, scritto in un\'altra forma, non è un cambio: passa senza conferma', function () {
    // Registrato con un IBAN diverso dall'anagrafica e confermato: rimandarlo con gli spazi, in minuscolo o con lo spazio che non si spezza
    // non lo cambia, e la pagina non deve chiedere di nuovo la conferma.
    [$pagamento, $modifica] = pagamentoDaModificare('IT60X0542811101000000123456', 'IT28W8000000292100645211208');

    foreach (['it28 w800 0000 2921 0064 5211 208', "IT28\u{00A0}W8000000292100645211208", ' IT28W8000000292100645211208 '] as $scritto) {
        expect((new PagamentoFornitoreService)->aggiornaPagamento($pagamento->fresh(), $modifica + ['iban_beneficiario' => $scritto])->id)->toBe($pagamento->id);
    }
});
