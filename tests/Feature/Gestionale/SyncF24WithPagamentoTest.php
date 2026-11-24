<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

require_once __DIR__ . '/GestionaleTestHelpers.php';

use App\Enums\TipoAllocazioneFattura;
use App\Events\Gestionale\FatturaRegistrata;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Listeners\Gestionale\SyncF24WithPagamento;
use App\Listeners\Gestionale\SyncScadenziarioWithFattura;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\User;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Test del listener SyncF24WithPagamento (refactor v1.9.1).
 *
 * Verifica la modifica di correttezza CENTRALE del rilascio: la ritenuta d'acconto
 * matura al PAGAMENTO, non alla registrazione della fattura
 * (D.P.R. 602/1973, art. 2, c. 1-bis).
 *
 * Dalla 1.12.0-beta.14 (Coda 718, decisione 675) il listener non scrive più un promemoria per pagamento con il 16 del mese
 * dopo: allinea i promemoria del condominio (`PromemoriaDelleRitenute`), e la ritenuta di un pagamento entra nel «Prepara le
 * deleghe F24» del condominio con la data del cumulo. Qui il fornitore è un professionista al 20 %: € 200,00 superano la
 * soglia di € 100,00 nel mese, e la data del cumulo è il 16 del mese dopo, come prima.
 *
 * Approccio: invochiamo il listener a mano — (new SyncF24WithPagamento())->handle(...) —
 * esattamente come la suite inbox fa con SyncScadenziarioWithPagamento. Questo prova la
 * LOGICA del listener (crea il task giusto, con importo e scadenza corretti, e lo NON crea
 * nei casi che deve saltare). NON prova il wiring di auto-discovery, cioè che Laravel lo
 * chiami davvero quando l'evento parte: quella metà è coperta dal controller test che fa
 * Event::assertDispatched(PagamentoRegistrato).
 *
 * Nota: setupPagamentiService() fa Event::fake() su FatturaRegistrata / PagamentoRegistrato /
 * PagamentoStornato. Nessun listener parte da solo durante la registrazione di fattura e
 * pagamento, quindi la tabella eventi resta pulita e l'unico task presente è quello che
 * creiamo noi invocando esplicitamente il listener.
 */

uses(RefreshDatabase::class)->group('inbox', 'pagamenti', 'f24');

beforeEach(function () {
    // La categoria dei promemoria, come la scrive l'installazione (`CategoriaEventoSeeder`).
    CategoriaEvento::firstOrCreate(
        ['name' => 'Scadenze amministrative'],
        ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']
    );

    if (!User::find(1)) {
        User::factory()->create(['id' => 1]);
    }
});

/**
 * Registra una fattura CON ritenuta e la porta a valori deterministici
 * (netto 1000€, ritenuta 200€) così che pagando il totale la ritenuta del
 * pagamento sia esattamente 200€. Stesso pattern del test "ritenute dacconto"
 * della suite service.
 */
if (!function_exists('registraFatturaConRitenuta')) {
    function registraFatturaConRitenuta(array $ctx): FatturaPassiva
    {
        [$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;
        
        $fornitore->update([
            'soggetto_ritenuta' => true, 
            'perc_ritenuta' => 20, 
            'perc_imponibile_ritenuta' => 100,
            'codice_tributo' => '1040'
        ]);

        $fattura = (new FatturaPassivaService())->registraFattura(
            datiBase([$condominio, $esercizio, $gestione, $fornitore], [
                'applica_ritenuta' => true,
                'righe'            => [[
                    'descrizione'        => 'Prestazione con ritenuta',
                    'importo_imponibile' => 1000,
                    'aliquota_iva'       => 0,
                    'conto_id'           => $capitolo->id,
                    'is_sopravvenienza'  => false,
                ]],
            ]),
            $condominio->id
        );

        // Forza i valori per una matematica pulita (come nel test pro-quota della suite service).
        $fattura->update(['netto_a_pagare' => 100000, 'importo_ritenuta' => 20000]);

        return $fattura;
    }
}

// ════════════════════════════════════════════════════════════════════════════
// CREAZIONE TASK F24 AL PAGAMENTO
// ════════════════════════════════════════════════════════════════════════════

it('crea il task F24 al pagamento di una fattura con ritenuta, con importo e scadenza corretti', function () {
    $ctx     = setupPagamentiService();
    $service = new PagamentoFornitoreService();
    $fattura = registraFatturaConRitenuta($ctx);

    // Pago il totale → ritenuta del pagamento = 200€ (20000 cents).
    // data_pagamento fissa: il 16 del mese successivo (2026-02-16) è un LUNEDÌ → nessuno spostamento.
    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'data_pagamento' => '2026-01-10',
        'allocazioni'    => [[
            'fattura_id'             => $fattura->id,
            'tipo'                   => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 100000,
        ]],
    ]));

    // Precondizione: il pagamento ha DAVVERO la ritenuta, altrimenti il test non proverebbe nulla.
    $pagamento->refresh();
    expect($pagamento->importo_ritenuta)->toEqual(20000);

    // Esegue il listener VERO (lo chiamiamo a mano: l'Event::fake non lo bypassa).
    (new SyncF24WithPagamento())->handle(new PagamentoRegistrato($pagamento));

    // Coda 718: un promemoria per condominio, «Prepara le deleghe F24», non uno per pagamento.
    $task = Evento::where('meta->context->prepara_deleghe', true)
        ->where('meta->type', 'versamento_ritenuta')
        ->sole();

    expect($task->meta['requires_action'])->toBeTrue();
    expect($task->is_completed)->toBeFalse();
    expect((int) $task->meta['importo'])->toEqual(20000);
    expect((int) $task->meta['numero_ritenute'])->toEqual(1);
    expect($task->meta['context']['is_f24'])->toBeTrue();
    expect($task->visibility)->toEqual('hidden');
    expect(Evento::where('meta->context->pagamento_id', $pagamento->id)->exists())->toBeFalse();

    // € 200,00 > € 100,00 a gennaio: il 16 del mese dopo, lunedì 16/02/2026, ore 09:00.
    expect(Carbon::parse($task->start_time)->format('Y-m-d H:i'))->toEqual('2026-02-16 09:00');
});

it('1.12.0-beta.13 (§7 del registro delle modifiche) — il promemoria di un pagamento senza una persona non ha autore: mai il primo utente', function () {
    // Il primo utente c'è (lo crea il `beforeEach`), ed era lui che il promemoria firmava come autore quando il pagamento non ne aveva uno.
    $ctx     = setupPagamentiService();
    $service = new PagamentoFornitoreService();
    $fattura = registraFatturaConRitenuta($ctx);
    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'data_pagamento' => '2026-01-10',
        'allocazioni'    => [['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => 100000]],
    ]))->fresh();
    $pagamento->forceFill(['user_id' => null])->save();

    (new SyncF24WithPagamento())->handle(new PagamentoRegistrato($pagamento));

    $task = Evento::where('meta->context->prepara_deleghe', true)->where('meta->type', 'versamento_ritenuta')->firstOrFail();
    expect(User::query()->whereKey(1)->exists())->toBeTrue()
        ->and($task->created_by)->toBeNull();
});

it('sposta la scadenza F24 al lunedì se il 16 cade nel weekend', function () {
    $ctx     = setupPagamentiService();
    $service = new PagamentoFornitoreService();
    $fattura = registraFatturaConRitenuta($ctx);

    // data_pagamento 2026-04-10 → 16 del mese successivo = 2026-05-16 (SABATO)
    //                           → spostato a LUNEDÌ 2026-05-18.
    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'data_pagamento' => '2026-04-10',
        'allocazioni'    => [[
            'fattura_id'             => $fattura->id,
            'tipo'                   => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 100000,
        ]],
    ]));

    (new SyncF24WithPagamento())->handle(new PagamentoRegistrato($pagamento->refresh()));

    $task = Evento::where('meta->context->prepara_deleghe', true)
        ->where('meta->type', 'versamento_ritenuta')
        ->sole();

    expect(Carbon::parse($task->start_time)->format('Y-m-d H:i'))->toEqual('2026-05-18 09:00');
});

// ════════════════════════════════════════════════════════════════════════════
// GUARD — QUANDO IL TASK NON DEVE NASCERE
// ════════════════════════════════════════════════════════════════════════════

it('NON crea il task F24 se il pagamento non ha ritenuta', function () {
    $ctx     = setupPagamentiService();
    $service = new PagamentoFornitoreService();
    $fattura = registraFatturaServiceTest($ctx); // fattura standard, nessuna ritenuta

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));
    $pagamento->refresh();

    // Precondizione: nessuna ritenuta sul pagamento.
    expect((int) $pagamento->importo_ritenuta)->toEqual(0);

    (new SyncF24WithPagamento())->handle(new PagamentoRegistrato($pagamento));

    expect(Evento::where('meta->type', 'versamento_ritenuta')->exists())->toBeFalse();
});

it('NON crea il task F24 per un record di storno (pagamento_padre_id valorizzato)', function () {
    $ctx     = setupPagamentiService();
    $service = new PagamentoFornitoreService();
    $fattura = registraFatturaConRitenuta($ctx);

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'allocazioni' => [[
            'fattura_id'             => $fattura->id,
            'tipo'                   => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 100000,
        ]],
    ]));

    $storno = $service->stornaPagamento($pagamento, 'Insoluto');

    // Precondizione: lo storno è un record figlio (la ritenuta è già stata annullata).
    expect($storno->pagamento_padre_id)->not->toBeNull();

    (new SyncF24WithPagamento())->handle(new PagamentoRegistrato($storno->refresh()));

    // Nessun promemoria aperto: lo storno non ha ritenuta da versare, e il pagamento stornato non è più «da versare». (Il «Prepara»
    // del pagamento originale, se `PagamentoRegistrato` è arrivato al listener, l'ha chiuso lo storno.)
    expect(Evento::where('meta->type', 'versamento_ritenuta')->where('is_completed', false)->exists())->toBeFalse()
        ->and(Evento::where('meta->context->pagamento_id', $storno->id)->exists())->toBeFalse();
});

// ════════════════════════════════════════════════════════════════════════════
// REGRESSIONE DEL REFACTOR — L'F24 NON NASCE PIÙ ALLA REGISTRAZIONE FATTURA
// ════════════════════════════════════════════════════════════════════════════

it('NON crea il task F24 alla registrazione della fattura (la ritenuta matura al pagamento)', function () {
    $ctx     = setupPagamentiService();
    $fattura = registraFatturaConRitenuta($ctx);

    // Esegue il listener sulla registrazione fattura: deve creare il task "pagamento_fornitore",
    // ma NON un task ritenuta — quello è stato spostato su SyncF24WithPagamento.
    (new SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura, 1));

    $taskRitenuta = Evento::where('meta->type', 'versamento_ritenuta')->first();

    expect($taskRitenuta)->toBeNull();
});
