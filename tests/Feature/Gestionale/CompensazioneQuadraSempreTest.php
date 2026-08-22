<?php

/**
 * # I payload che la schermata produce sono quelli che il motore accetta
 *
 * ## Il buco che questo file chiude
 *
 * Fino alla beta.66 esisteva un test che provava la compensazione — `PagamentoFornitoreControllerTest`
 * — e passava. Costruiva a mano i **tre** record che il motore pretende, e dimostrava che il motore
 * funziona. Il che era vero, e inutile: la schermata ne emetteva **due**, e non c'era niente che
 * confrontasse le due cose. Il motore era provato, l'interfaccia era provata, e nel mezzo il
 * pulsante «Compensa automaticamente» falliva sempre.
 *
 * ⚠️ **È la forma di buco più difficile da vedere**, perché entrambi i lati sono verdi. Serve una
 * prova che tenga insieme *cosa spedisce l'uno* e *cosa accetta l'altro*.
 *
 * ## Come i due lati restano legati
 *
 * I casi qui sotto sono **gli stessi** di `resources/js/lib/gestionale/fatture/netting.test.ts`, con
 * gli stessi importi e gli stessi payload attesi. La duplicazione è voluta e va mantenuta:
 *
 * - di là si prova che il modulo **produce** quel payload;
 * - di qua si prova che il motore lo **accetta** e ne ricava una scrittura quadrata.
 *
 * Se uno dei due lati cambia da solo, uno dei due file diventa rosso. È l'unica cosa che tiene
 * insieme un calcolo scritto in TypeScript e un motore scritto in PHP senza farli girare insieme.
 */

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Registra una fattura di un importo dato, in euro di imponibile. */
function fatturaDa(array $ctx, string $numero, int $imponibileEuro): FatturaPassiva
{
    return registraFatturaServiceTest($ctx, [
        'numero_documento' => $numero,
        'righe' => [[
            'descrizione'        => "Servizio {$numero}",
            'importo_imponibile' => $imponibileEuro,
            'aliquota_iva'       => 0,
            'conto_id'           => $ctx[5]->id,
            'is_sopravvenienza'  => false,
        ]],
    ]);
}

function notaDa(array $ctx, string $numero, int $imponibileEuro): FatturaPassiva
{
    return registraFatturaServiceTest($ctx, [
        'tipo_documento'   => 'nota_credito',
        'numero_documento' => $numero,
        'righe' => [[
            'descrizione'        => "Storno {$numero}",
            'importo_imponibile' => $imponibileEuro,
            'aliquota_iva'       => 0,
            'conto_id'           => $ctx[5]->id,
            'is_sopravvenienza'  => false,
        ]],
    ]);
}

it('⚠️ fattura € 1.000,00 + nota € 200,00 → bonifico € 800,00: il caso della specifica', function () {
    // `docs/pagamenti_fatture.md`, Decisione 1. La fattura compare **due volte**.
    $ctx = setupPagamentiService();
    $ft = fatturaDa($ctx, 'FT-1', 1000);
    $nc = notaDa($ctx, 'NC-1', 200);

    $pagamento = app(PagamentoFornitoreService::class)->registraPagamento(
        datiPagamento($ctx, $ft, ['allocazioni' => [
            ['fattura_id' => $ft->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,     'importo_allocato_cents' => 80000],
            ['fattura_id' => $ft->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 20000],
            ['fattura_id' => $nc->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 20000],
        ]]),
        $ctx[0]->id
    );

    assertQuadraturaPerfetta((int) $pagamento->scrittura_contabile_id);

    expect($ft->fresh()->stato_pagamento->value)->toBe('pagata', 'La fattura non risulta chiusa.');
    expect($nc->fresh()->residuo)->toBe(0, 'Il credito della nota non risulta consumato.');
    expect((int) $pagamento->importo_netto)->toBe(80000,
        'Il bonifico non è € 800,00: la parte compensata sta uscendo di cassa.'
    );
});

it('⚠️ e il payload che la schermata emetteva prima viene rifiutato', function () {
    // Un record per documento, la fattura tipizzata `pagamento` per intero. È **esattamente** ciò
    // che `syncAllocazioni()` produceva fino alla beta.66, e la prova che il difetto era reale:
    // non un caso limite, il comportamento normale di quel pulsante.
    $ctx = setupPagamentiService();
    $ft = fatturaDa($ctx, 'FT-1', 1000);
    $nc = notaDa($ctx, 'NC-1', 200);

    expect(fn () => app(PagamentoFornitoreService::class)->registraPagamento(
        datiPagamento($ctx, $ft, ['allocazioni' => [
            ['fattura_id' => $ft->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,     'importo_allocato_cents' => 100000],
            ['fattura_id' => $nc->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 20000],
        ]]),
        $ctx[0]->id
    ))->toThrow('Sbilancio rilevato tra DARE (€ 1.000,00) e AVERE (€ 1.200,00)');

    // ⚠️ Il messaggio per esteso, non la sola classe dell'eccezione: **è il difetto**, con le sue
    // cifre. Chi rilegge questo test fra un anno deve vedere subito di quanto sbilanciava e perché
    // — € 200,00, cioè il valore esatto della nota, contato una volta sola invece che due.
    expect($ft->fresh()->stato_pagamento->value)->toBe('aperta',
        'La fattura risulta toccata da un pagamento che è stato rifiutato.'
    );
});

it('compensazione pura: nessun euro esce di cassa', function () {
    $ctx = setupPagamentiService();
    $ft = fatturaDa($ctx, 'FT-1', 500);
    $nc = notaDa($ctx, 'NC-1', 500);

    $pagamento = app(PagamentoFornitoreService::class)->registraPagamento(
        datiPagamento($ctx, $ft, ['allocazioni' => [
            ['fattura_id' => $ft->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 50000],
            ['fattura_id' => $nc->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 50000],
        ]]),
        $ctx[0]->id
    );

    expect((int) $pagamento->importo_netto)->toBe(0);
    expect($ft->fresh()->stato_pagamento->value)->toBe('pagata');
    expect($nc->fresh()->residuo)->toBe(0);

    assertQuadraturaPerfetta((int) $pagamento->scrittura_contabile_id);
});

it('nota più grande delle fatture: si usa solo la parte che serve, il resto resta credito', function () {
    // Il modulo taglia il credito a quanto le fatture possono assorbire — è l'invariante
    // Σ(compensazione su fatture) = Σ(compensazione su note). Qui si prova che il payload tagliato
    // è accettato e che l'eccedenza **resta disponibile** invece di essere bruciata.
    $ctx = setupPagamentiService();
    $ft = fatturaDa($ctx, 'FT-1', 500);
    $nc = notaDa($ctx, 'NC-1', 800);

    app(PagamentoFornitoreService::class)->registraPagamento(
        datiPagamento($ctx, $ft, ['allocazioni' => [
            ['fattura_id' => $ft->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 50000],
            ['fattura_id' => $nc->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 50000],
        ]]),
        $ctx[0]->id
    );

    expect($ft->fresh()->stato_pagamento->value)->toBe('pagata');
    expect($nc->fresh()->residuo)->toBe(30000,
        'Il credito non consumato deve restare sulla nota: € 300,00 su una nota da € 800,00.'
    );
    expect($nc->fresh()->stato_pagamento->value)->toBe('parziale');
});
