<?php

namespace App\Listeners\Gestionale;

use App\Enums\StatoPagamentoFattura;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Events\Gestionale\PagamentoStornato;
use App\Models\Evento;
use App\Services\Gestionale\InboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SyncScadenziarioWithPagamento implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    /**
     * Sottoscrive la classe a più eventi.
     * Utilizzato dal sistema di event auto-discovery o event mapping manuale.
     */
    public function subscribe($events): void
    {
        $events->listen(
            PagamentoRegistrato::class,
            [SyncScadenziarioWithPagamento::class, 'handlePagamentoRegistrato']
        );

        $events->listen(
            PagamentoStornato::class,
            [SyncScadenziarioWithPagamento::class, 'handlePagamentoStornato']
        );
    }

    public function handlePagamentoRegistrato(PagamentoRegistrato $event): void
    {
        $pagamento = $event->pagamento;

        // Recuperiamo tutte le fatture toccate dal pagamento
        // La relazione fatture() attraversa la pivot fattura_scrittura
        $fatture = $pagamento->fatture()->get();

        foreach ($fatture as $fattura) {
            $isPagata = ($fattura->stato_pagamento === StatoPagamentoFattura::PAGATA);

            // Trova l'evento Inbox relativo a questa fattura
            $inboxEvent = Evento::where('meta->context->fattura_id', $fattura->id)
                ->where('meta->type', 'pagamento_fornitore')
                ->first();

            if ($inboxEvent) {
                $meta = $inboxEvent->meta ?? [];
                
                // Aggiorniamo lo stato di completamento in base allo stato della fattura
                $meta['is_completed'] = $isPagata;
                $meta['requires_action'] = !$isPagata;
                
                $inboxEvent->meta = $meta;
                $inboxEvent->is_completed = $isPagata;
                $inboxEvent->save();
            }
        }

        // Invalida la cache dell'Inbox Admin per far riflettere i nuovi conteggi
        InboxService::clearAdminCache();
    }

    public function handlePagamentoStornato(PagamentoStornato $event): void
    {
        $pagamento = $event->pagamento;

        // Quando storniamo, le fatture tornano 'aperta' o 'parziale'
        $fatture = $pagamento->fatture()->get();

        foreach ($fatture as $fattura) {
            $inboxEvent = Evento::where('meta->context->fattura_id', $fattura->id)
                ->where('meta->type', 'pagamento_fornitore')
                ->first();

            if ($inboxEvent) {
                $meta = $inboxEvent->meta ?? [];
                
                // Lo storno riapre la necessità di agire
                $meta['is_completed'] = false;
                $meta['requires_action'] = true;
                
                $inboxEvent->meta = $meta;
                $inboxEvent->is_completed = false;
                $inboxEvent->save();
            }
        }

        InboxService::clearAdminCache();
    }
}
