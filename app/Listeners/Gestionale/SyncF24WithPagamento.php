<?php

namespace App\Listeners\Gestionale;

use App\Events\Gestionale\PagamentoAggiornato;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Crea il task "Versare F24 Ritenuta" nell'Admin Inbox quando un pagamento
 * fornitore con ritenuta d'acconto viene registrato.
 *
 * Il task compare SOLO al momento del pagamento — non alla registrazione della
 * fattura — perché la ritenuta matura fiscalmente nel mese successivo all'effettivo
 * esborso (D.P.R. 602/1973, art. 2, comma 1-bis). La scadenza è il 16 del mese
 * successivo a data_pagamento, spostata al lunedì se cade di sabato o domenica.
 *
 * Implementazione: auto-discovery via metodo subscribe(), stessa convenzione di
 * SyncScadenziarioWithPagamento. $afterCommit = true obbligatorio: il lock
 * pessimistico del pagamento deve essere già rilasciato prima che questo
 * listener legga i dati.
 */
class SyncF24WithPagamento implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    /**
     * Auto-registra il listener sull'evento PagamentoRegistrato.
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
    }

    public function handle(PagamentoRegistrato $event): void
    {
        $pagamento = $event->pagamento;

        // Guard: nessuna ritenuta → nessun F24 da versare
        if (!$pagamento->importo_ritenuta || $pagamento->importo_ritenuta <= 0) {
            return;
        }

        // Guard: se è un record di storno, la ritenuta è già stata annullata
        if ($pagamento->pagamento_padre_id !== null) {
            return;
        }

        $pagamento->loadMissing(['fornitore', 'contoCorrente']);

        $condominio = $pagamento->condominio;
        $fornitore  = $pagamento->fornitore;

        if (!$condominio || !$fornitore) {
            Log::warning("SyncF24WithPagamento: fornitore o condominio mancante per pagamento #{$pagamento->id}");
            return;
        }

        // ── Calcolo scadenza F24 ──────────────────────────────────────────────
        // 16 del mese successivo alla data del pagamento, lunedì se weekend.
        $dataPagamento = $pagamento->data_pagamento;

        if (!$dataPagamento) {
            Log::warning("SyncF24WithPagamento: data_pagamento mancante per pagamento #{$pagamento->id}");
            return;
        }

        $scadenzaF24 = $dataPagamento->copy()->addMonthNoOverflow()->day(16)->setTime(9, 0);

        if ($scadenzaF24->isWeekend()) {
            $scadenzaF24->next('Monday')->setTime(9, 0);
        }

        // ── Creazione task Inbox ──────────────────────────────────────────────
        try {
            $catAdmin = CategoriaEvento::where('name', 'Scadenze amministrative')->firstOrFail();

            $importoFormatted = number_format($pagamento->importo_ritenuta / 100, 2, ',', '.');

            Evento::updateOrCreate(
                [
                    'meta->context->pagamento_id' => $pagamento->id,
                    'meta->type'                  => 'versamento_ritenuta',
                ],
                [
                    'title'       => "F24 Ritenuta — {$fornitore->ragione_sociale} ({$condominio->nome})",
                    'start_time'  => $scadenzaF24,
                    'end_time'    => $scadenzaF24->copy()->addHour(),
                    'created_by'  => $pagamento->user_id ?? User::first()?->id, // evento di sistema, ma il database richiede un id valido
                    'description' => "Versare ritenuta d'acconto (Cod. {$fornitore->codice_tributo}).\n"
                                   . "Importo: {$importoFormatted} €\n"
                                   . "Pagamento registrato il: {$dataPagamento->format('d/m/Y')}",
                    'category_id' => $catAdmin->id,
                    'visibility'  => 'hidden',
                    'is_approved' => true,
                    'meta'        => [
                        'type'            => 'versamento_ritenuta',
                        'requires_action' => true,
                        'is_completed'    => false,
                        'condominio_nome' => $condominio->nome,
                        'importo'         => $pagamento->importo_ritenuta,
                        'fornitore'       => $fornitore->ragione_sociale,
                        'titolo_azione'   => 'Vedi pagamento',
                        'action_url'      => route('admin.gestionale.pagamenti-fornitori.show', [
                            'condominio' => $condominio->id,
                            'pagamento'  => $pagamento->id,
                        ]),
                        'context' => [
                            'pagamento_id'  => $pagamento->id,
                            'fornitore_id'  => $fornitore->id,
                            'is_f24'        => true,
                        ],
                    ],
                ]
            )->condomini()->syncWithoutDetaching([$condominio->id]);

            InboxService::clearAdminCache();

            Log::info("SyncF24WithPagamento: task F24 creato/aggiornato per pagamento #{$pagamento->id}, scadenza {$scadenzaF24->format('d/m/Y')}");

        } catch (\Exception $e) {
            Log::error("SyncF24WithPagamento: errore per pagamento #{$pagamento->id}: " . $e->getMessage());
        }
    }

    /**
     * Aggiorna, crea o chiude il task F24 Inbox quando un pagamento viene modificato.
     *
     * Casistiche:
     *  - data_pagamento cambiata e ritenuta > 0 → ricalcola scadenza F24
     *  - ritenuta da 0 → valore > 0 → crea task F24 (prima non esisteva)
     *  - ritenuta da valore → 0 → marca task F24 come completato
     *  - solo importo ritenuta cambiato (non data) → aggiorna importo nel meta
     */
    public function handleAggiornato(PagamentoAggiornato $event): void
    {
        $pagamento             = $event->pagamento;
        $importoRitenutaBefore = $event->importoRitenutaBefore;
        $importoRitenutaAfter  = $pagamento->importo_ritenuta ?? 0;

        // Guard: storno — non dovrebbe mai arrivare qui, ma sicurezza prima di tutto
        if ($pagamento->pagamento_padre_id !== null) {
            return;
        }

        // Caso: ritenuta era > 0, ora è 0 → chiudi il task F24
        if ($importoRitenutaBefore > 0 && $importoRitenutaAfter <= 0) {
            Evento::where('meta->context->pagamento_id', $pagamento->id)
                ->where('meta->type', 'versamento_ritenuta')
                ->update(['is_completed' => true]);

            InboxService::clearAdminCache();

            Log::info("SyncF24WithPagamento: task F24 chiuso per pagamento #{$pagamento->id} (ritenuta azzerata)");
            return;
        }

        // Caso: nessuna ritenuta → nessun F24
        if ($importoRitenutaAfter <= 0) {
            return;
        }

        // Caso: ritenuta è ora > 0 (era 0 o è cambiata, o data è cambiata) → crea/aggiorna
        // Ricrea il task con la nuova scadenza — updateOrCreate con chiave pagamento_id è idempotente
        $pagamento->loadMissing(['fornitore', 'contoCorrente']);
        $condominio = $pagamento->condominio;
        $fornitore  = $pagamento->fornitore;

        if (!$condominio || !$fornitore) {
            Log::warning("SyncF24WithPagamento::handleAggiornato: fornitore o condominio mancante per pagamento #{$pagamento->id}");
            return;
        }

        $dataPagamento = $pagamento->data_pagamento;
        if (!$dataPagamento) {
            return;
        }

        try {
            $catAdmin = CategoriaEvento::where('name', 'Scadenze amministrative')->firstOrFail();

            $scadenzaF24 = $dataPagamento->copy()->addMonthNoOverflow()->day(16)->setTime(9, 0);
            if ($scadenzaF24->isWeekend()) {
                $scadenzaF24->next('Monday')->setTime(9, 0);
            }

            $importoFormatted = number_format($importoRitenutaAfter / 100, 2, ',', '.');

            Evento::updateOrCreate(
                [
                    'meta->context->pagamento_id' => $pagamento->id,
                    'meta->type'                  => 'versamento_ritenuta',
                ],
                [
                    'title'        => "F24 Ritenuta — {$fornitore->ragione_sociale} ({$condominio->nome})",
                    'start_time'   => $scadenzaF24,
                    'end_time'     => $scadenzaF24->copy()->addHour(),
                    'created_by'   => $pagamento->user_id ?? User::first()?->id,
                    'description'  => "Versare ritenuta d'acconto (Cod. {$fornitore->codice_tributo}).\n"
                                    . "Importo: {$importoFormatted} €\n"
                                    . "Pagamento aggiornato il: {$dataPagamento->format('d/m/Y')}",
                    'category_id'  => $catAdmin->id,
                    'visibility'   => 'hidden',
                    'is_approved'  => true,
                    'is_completed' => false,
                    'meta'         => [
                        'type'            => 'versamento_ritenuta',
                        'requires_action' => true,
                        'is_completed'    => false,
                        'condominio_nome' => $condominio->nome,
                        'importo'         => $importoRitenutaAfter,
                        'fornitore'       => $fornitore->ragione_sociale,
                        'titolo_azione'   => 'Vedi pagamento',
                        'action_url'      => route('admin.gestionale.pagamenti-fornitori.show', [
                            'condominio' => $condominio->id,
                            'pagamento'  => $pagamento->id,
                        ]),
                        'context' => [
                            'pagamento_id'  => $pagamento->id,
                            'fornitore_id'  => $fornitore->id,
                            'is_f24'        => true,
                        ],
                    ],
                ]
            )->condomini()->syncWithoutDetaching([$condominio->id]);

            InboxService::clearAdminCache();

            Log::info("SyncF24WithPagamento: task F24 aggiornato per pagamento #{$pagamento->id}, scadenza {$scadenzaF24->format('d/m/Y')}");

        } catch (\Exception $e) {
            Log::error("SyncF24WithPagamento::handleAggiornato: errore per pagamento #{$pagamento->id}: " . $e->getMessage());
        }
    }
}
