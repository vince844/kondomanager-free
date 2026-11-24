<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Listeners\Gestionale;

use App\Events\Gestionale\PagamentoAggiornato;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Events\Gestionale\PagamentoStornato;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\F24\PromemoriaDelleRitenute;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Tiene i promemoria delle ritenute da versare quando un pagamento con la ritenuta d'acconto si registra, si modifica o si storna.
 *
 * Il promemoria nasce SOLO al pagamento — non alla registrazione della fattura — perché la ritenuta matura fiscalmente all'atto
 * dell'esborso (D.P.R. 602/1973, art. 2, comma 1-bis).
 *
 * Dalla 1.12.0-beta.14 (Coda 718, decisione 675) il listener non scrive più un promemoria per pagamento con il 16 del mese dopo: chiede
 * a `PromemoriaDelleRitenute::allinea()` di allineare quelli del condominio, uno per delega e un «Prepara le deleghe F24» per le
 * ritenute ancora senza delega, con la data del cumulo. Prima la stessa ritenuta aveva due date: il 16/04 nell'Inbox, il 16/06 nella
 * delega (caso F di t=202).
 *
 * Implementazione: auto-discovery via metodo subscribe(), stessa convenzione di SyncScadenziarioWithPagamento. $afterCommit = true
 * obbligatorio: il lock pessimistico del pagamento deve essere già rilasciato prima che questo listener legga i dati.
 */
class SyncF24WithPagamento implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    /**
     * Auto-registra il listener sugli eventi del pagamento.
     */
    public function subscribe($events): void
    {
        $events->listen(
            PagamentoRegistrato::class,
            [SyncF24WithPagamento::class, 'handle']
        );

        $events->listen(
            PagamentoAggiornato::class,
            [SyncF24WithPagamento::class, 'handleAggiornato']
        );

        // Senza questa iscrizione il promemoria restava aperto dopo uno storno, e
        // segnalava un versamento non più dovuto (design §8 punto 4).
        $events->listen(
            PagamentoStornato::class,
            [SyncF24WithPagamento::class, 'handleStornato']
        );
    }

    /** Un pagamento registrato con la ritenuta: la ritenuta entra nel «Prepara le deleghe F24» del condominio. */
    public function handle(PagamentoRegistrato $event): void
    {
        $pagamento = $event->pagamento;

        // Nessuna ritenuta, o il record di storno (la ritenuta è già stata annullata): niente da versare.
        if ((int) ($pagamento->importo_ritenuta ?? 0) <= 0 || $pagamento->pagamento_padre_id !== null) {
            return;
        }

        $this->allinea($pagamento, 'handle');
    }

    /**
     * Un pagamento modificato: la ritenuta nuova, tolta o con un'altra data cambia il cumulo, e con lui la data e la cifra del
     * promemoria. Senza ritenuta prima e dopo non c'è niente da allineare.
     */
    public function handleAggiornato(PagamentoAggiornato $event): void
    {
        $pagamento = $event->pagamento;

        if ($pagamento->pagamento_padre_id !== null) {
            return;
        }
        if ($event->importoRitenutaBefore <= 0 && (int) ($pagamento->importo_ritenuta ?? 0) <= 0) {
            return;
        }

        $this->allinea($pagamento, 'handleAggiornato');
    }

    /**
     * Un pagamento stornato: la sua ritenuta non è più da versare (difetto §8 punto 4 del design: prima il promemoria restava aperto).
     * L'evento porta il pagamento ORIGINALE, non il record di storno.
     */
    public function handleStornato(PagamentoStornato $event): void
    {
        $pagamento = $event->pagamento;

        if ((int) ($pagamento->importo_ritenuta ?? 0) <= 0) {
            return;
        }

        $this->allinea($pagamento, 'handleStornato');
    }

    private function allinea(PagamentoFornitore $pagamento, string $dove): void
    {
        $condominio = $pagamento->condominio;

        if (! $condominio) {
            Log::warning("SyncF24WithPagamento::{$dove}: condominio mancante per il pagamento #{$pagamento->id}");

            return;
        }

        try {
            app(PromemoriaDelleRitenute::class)->allinea($condominio);
        } catch (\Throwable $e) {
            Log::error("SyncF24WithPagamento::{$dove}: errore per il pagamento #{$pagamento->id}: ".$e->getMessage());
        }
    }
}
