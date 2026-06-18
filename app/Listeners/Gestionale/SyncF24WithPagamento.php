<?php

namespace App\Listeners\Gestionale;

use App\Events\Gestionale\PagamentoRegistrato;
use App\Models\CategoriaEvento;
use App\Models\Evento;
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
                    'created_by'  => null, // evento di sistema, non riconducibile a un utente specifico
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
}
