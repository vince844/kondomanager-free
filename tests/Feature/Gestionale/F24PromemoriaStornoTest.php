<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il promemoria «Versare F24 Ritenuta» dopo lo storno del pagamento che l'ha generato.
 *
 * Difetto §8 punto 4 del design: `SyncF24WithPagamento::subscribe()` iscriveva il listener a
 * `PagamentoRegistrato` e `PagamentoAggiornato`, ma **non** a `PagamentoStornato`. Stornando
 * il pagamento, la ritenuta veniva annullata in contabilità mentre il task restava aperto
 * nell'Inbox dell'amministratore, a chiedere il versamento di una somma non più dovuta.
 *
 * Chiuderlo richiedeva accorgersene: un promemoria fiscale che sopravvive al fatto che lo ha
 * generato è peggio di un promemoria assente, perché chi lo legge si fida.
 *
 * Dalla 1.12.0-beta.14 (Coda 718) lo storno allinea i promemoria del condominio (`PromemoriaDelleRitenute`): il promemoria
 * per pagamento delle versioni di prima si chiude, e una ritenuta stornata non entra nel «Prepara le deleghe F24». I promemoria
 * di un altro condominio non si toccano.
 */

use App\Events\Gestionale\PagamentoStornato;
use App\Listeners\Gestionale\SyncF24WithPagamento;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;

require_once __DIR__.'/GestionaleTestHelpers.php';

/** Il task F24 come lo creava il listener fino alla 1.12.0-beta.13, agganciato a un pagamento e al suo condominio. */
function creaPromemoriaF24(int $pagamentoId, int $condominioId): Evento
{
    $categoria = CategoriaEvento::firstOrCreate(
        ['name' => 'Scadenze amministrative'],
        ['description' => 'Categoria di sistema per le scadenze fiscali']
    );

    $evento = Evento::create([
        'title' => 'F24 Ritenuta — test',
        'start_time' => now()->addMonth(),
        'end_time' => now()->addMonth()->addHour(),
        'created_by' => User::first()?->id ?? User::factory()->create()->id,
        'description' => 'Versare ritenuta',
        'category_id' => $categoria->id,
        'visibility' => 'hidden',
        'is_approved' => true,
        'is_completed' => false,
        'meta' => [
            'type' => 'versamento_ritenuta',
            'requires_action' => true,
            'context' => ['pagamento_id' => $pagamentoId, 'is_f24' => true],
        ],
    ]);
    $evento->condomini()->syncWithoutDetaching([$condominioId]);

    return $evento;
}

/** Una fattura con la ritenuta (appalto al 4 %, € 1.000,00 di imponibile: € 40,00), pagata per intero. */
function pagamentoConRitenutaPerStorno(array $ctx)
{
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100,
        'tipo_ritenuta' => 'appalto_4', 'natura_percipiente' => 'persona_fisica_irpef']);
    $ctx[3]->refresh();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    return (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));
}

test('il listener è iscritto anche a PagamentoStornato', function () {
    $eventi = app('events');
    $listener = new SyncF24WithPagamento;
    $listener->subscribe($eventi);

    expect($eventi->hasListeners(PagamentoStornato::class))->toBeTrue();
});

test('lo storno chiude il promemoria F24 del pagamento, e la ritenuta stornata non resta da versare', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;
    $pagamento = pagamentoConRitenutaPerStorno($ctx);
    expect((int) $pagamento->importo_ritenuta)->toBe(4000);
    $promemoria = creaPromemoriaF24($pagamento->id, $condominio->id);

    expect((bool) $promemoria->fresh()->is_completed)->toBeFalse();

    (new PagamentoFornitoreService)->stornaPagamento($pagamento->fresh(), 'Errore di battitura');
    (new SyncF24WithPagamento)->handleStornato(
        new PagamentoStornato($pagamento->fresh(), 'Errore di battitura')
    );

    expect((bool) $promemoria->fresh()->is_completed)->toBeTrue()
        ->and(Evento::where('meta->type', 'versamento_ritenuta')->where('is_completed', false)->exists())->toBeFalse();
});

test('lo storno non tocca il promemoria di un altro condominio', function () {
    $ctx = setupPagamentiService();
    $altro = setupPagamentiService();
    [$condominio] = $ctx;
    $pagamento = pagamentoConRitenutaPerStorno($ctx);

    $mio = creaPromemoriaF24($pagamento->id, $condominio->id);
    $altrui = creaPromemoriaF24($pagamento->id + 999, $altro[0]->id);

    (new PagamentoFornitoreService)->stornaPagamento($pagamento->fresh(), 'Errore');
    (new SyncF24WithPagamento)->handleStornato(
        new PagamentoStornato($pagamento->fresh(), 'Errore')
    );

    expect((bool) $mio->fresh()->is_completed)->toBeTrue()
        ->and((bool) $altrui->fresh()->is_completed)->toBeFalse();
});

test('senza promemoria da chiudere lo storno non solleva niente', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));

    (new SyncF24WithPagamento)->handleStornato(
        new PagamentoStornato($pagamento, 'Nessun task collegato')
    );

    expect(true)->toBeTrue();
});
