<?php

require_once __DIR__ . '/GestionaleTestHelpers.php';

use App\Enums\MetodoPagamento;
use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Support\Facades\DB;

uses()->group('inbox', 'pagamenti');

function setupPagamenti(): array
{
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $contoCorrenteId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'ruolo'         => 'conto_bancario',
        'codice'        => 'BANCA-TEST',
        'nome'          => 'Conto Corrente Test',
        'tipo'          => 'attivo',
        'categoria'     => 'crediti',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('casse')->insertGetId([
        'condominio_id'      => $condominio->id,
        'conto_contabile_id' => $contoCorrenteId,
        'nome'               => 'Conto Corrente Test',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    return [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo];
}

function registraFatturaTest(array $ctx, array $override = []): FatturaPassiva
{
    [$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;

    return (new FatturaPassivaService())->registraFattura(
        array_merge(
            datiBase([$condominio, $esercizio, $gestione, $fornitore], [
                'righe' => [[
                    'descrizione'        => 'Servizio Test',
                    'importo_imponibile' => 1000,
                    'aliquota_iva'       => 22,
                    'conto_id'           => $capitolo->id,
                    'is_sopravvenienza'  => false,
                ]],
            ]),
            $override
        ),
        $condominio->id
    );
}

beforeEach(function () {
    // Il setupPagamenti() è nel file GestionaleTestHelpers.php 
    // Assicuriamoci che la categoria evento "Scadenze amministrative" esista,
    // dato che il listener SyncScadenziarioWithFattura ne ha bisogno.
    CategoriaEvento::firstOrCreate(
        ['name' => 'Scadenze amministrative'],
        ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']
    );
    
    // Crea utente 1 se non esiste per il created_by
    if (!\App\Models\User::find(1)) {
        \App\Models\User::factory()->create(['id' => 1]);
    }
});

it('crea un task inbox pagamento_fornitore quando viene registrata una fattura passiva', function () {
    $ctx = setupPagamenti();
    $fattura = registraFatturaTest($ctx);
    
    // Siccome in setupContabile l'evento è finto, lanciamo il listener manualmente
    $listener = new \App\Listeners\Gestionale\SyncScadenziarioWithFattura();
    $listener->handle(new \App\Events\Gestionale\FatturaRegistrata($fattura, 1));

    // Verifica che il task inbox sia stato creato e richieda azione
    $inboxEvent = Evento::where('meta->context->fattura_id', $fattura->id)
        ->where('meta->type', 'pagamento_fornitore')
        ->first();

    expect($inboxEvent)->not->toBeNull()
        ->and($inboxEvent->meta['requires_action'])->toBeTrue()
        ->and($inboxEvent->meta['is_completed'])->toBeFalse()
        ->and($inboxEvent->is_completed)->toBeFalse();
});

it('segna il task inbox pagamento_fornitore come completato quando la fattura viene pagata', function () {
    $ctx = setupPagamenti();
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId] = $ctx;
    
    $fattura = registraFatturaTest($ctx);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithFattura())->handle(new \App\Events\Gestionale\FatturaRegistrata($fattura, 1));

    // Registra il pagamento
    $service = new PagamentoFornitoreService();
    $pagamento = clone $service->registraPagamento([
        'fornitore_id'                  => $fornitore->id,
        'condominio_id'                 => $condominio->id,
        'esercizio_id'                  => $esercizio->id,
        'conto_corrente_id'             => $contoCorrenteId,
        'data_pagamento'                => now()->format('Y-m-d'),
        'metodo_pagamento'              => MetodoPagamento::BONIFICO->value,
        'allow_overdraft'               => true,
        'iban_confermato_manualmente'   => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni'                   => [
            [
                'fattura_id'             => $fattura->id,
                'tipo'                   => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura->netto_a_pagare,
            ],
        ],
    ]);

    (new \App\Listeners\Gestionale\SyncScadenziarioWithPagamento())->handlePagamentoRegistrato(new \App\Events\Gestionale\PagamentoRegistrato($pagamento));

    // Verifica che il task inbox sia stato completato
    $inboxEvent = Evento::where('meta->context->fattura_id', $fattura->id)
        ->where('meta->type', 'pagamento_fornitore')
        ->first();

    expect($inboxEvent)->not->toBeNull()
        ->and($inboxEvent->meta['requires_action'])->toBeFalse()
        ->and($inboxEvent->meta['is_completed'])->toBeTrue()
        ->and($inboxEvent->is_completed)->toBeTrue();
});

it('riapre il task inbox pagamento_fornitore quando il pagamento viene stornato', function () {
    $ctx = setupPagamenti();
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId] = $ctx;
    
    $fattura = registraFatturaTest($ctx);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithFattura())->handle(new \App\Events\Gestionale\FatturaRegistrata($fattura, 1));

    $service = new PagamentoFornitoreService();
    $pagamento = $service->registraPagamento([
        'fornitore_id'                  => $fornitore->id,
        'condominio_id'                 => $condominio->id,
        'esercizio_id'                  => $esercizio->id,
        'conto_corrente_id'             => $contoCorrenteId,
        'data_pagamento'                => now()->format('Y-m-d'),
        'metodo_pagamento'              => MetodoPagamento::BONIFICO->value,
        'allow_overdraft'               => true,
        'iban_confermato_manualmente'   => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni'                   => [
            [
                'fattura_id'             => $fattura->id,
                'tipo'                   => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura->netto_a_pagare,
            ],
        ],
    ]);

    $pagamento = clone $pagamento;
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPagamento())->handlePagamentoRegistrato(new \App\Events\Gestionale\PagamentoRegistrato($pagamento));

    // Storna il pagamento
    $service->stornaPagamento($pagamento, 'Storno test');
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPagamento())->handlePagamentoStornato(new \App\Events\Gestionale\PagamentoStornato($pagamento, 'Storno test'));

    // Verifica che il task inbox sia tornato aperto
    $inboxEvent = Evento::where('meta->context->fattura_id', $fattura->id)
        ->where('meta->type', 'pagamento_fornitore')
        ->first();

    expect($inboxEvent)->not->toBeNull()
        ->and($inboxEvent->meta['requires_action'])->toBeTrue()
        ->and($inboxEvent->meta['is_completed'])->toBeFalse()
        ->and($inboxEvent->is_completed)->toBeFalse();
});
