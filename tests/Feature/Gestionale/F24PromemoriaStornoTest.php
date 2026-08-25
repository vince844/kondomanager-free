<?php

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
 */

use App\Events\Gestionale\PagamentoStornato;
use App\Listeners\Gestionale\SyncF24WithPagamento;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;

require_once __DIR__.'/GestionaleTestHelpers.php';

/** Il task F24 come lo crea il listener, agganciato a un pagamento. */
function creaPromemoriaF24(int $pagamentoId, int $condominioId): Evento
{
    $categoria = CategoriaEvento::firstOrCreate(
        ['name' => 'Scadenze amministrative'],
        ['description' => 'Categoria di sistema per le scadenze fiscali']
    );

    return Evento::create([
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
}

test('il listener è iscritto anche a PagamentoStornato', function () {
    $eventi = app('events');
    $listener = new SyncF24WithPagamento;
    $listener->subscribe($eventi);

    expect($eventi->hasListeners(PagamentoStornato::class))->toBeTrue();
});

test('lo storno chiude il promemoria F24 del pagamento', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));
    $promemoria = creaPromemoriaF24($pagamento->id, $condominio->id);

    expect((bool) $promemoria->fresh()->is_completed)->toBeFalse();

    (new SyncF24WithPagamento)->handleStornato(
        new PagamentoStornato($pagamento, 'Errore di battitura')
    );

    expect((bool) $promemoria->fresh()->is_completed)->toBeTrue();
});

test('lo storno non tocca il promemoria di un altro pagamento', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));

    $mio = creaPromemoriaF24($pagamento->id, $condominio->id);
    $altrui = creaPromemoriaF24($pagamento->id + 999, $condominio->id);

    (new SyncF24WithPagamento)->handleStornato(
        new PagamentoStornato($pagamento, 'Errore')
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
