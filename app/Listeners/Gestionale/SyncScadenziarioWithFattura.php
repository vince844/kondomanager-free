<?php

namespace App\Listeners\Gestionale;

use App\Enums\VisibilityStatus;
use App\Events\Gestionale\FatturaRegistrata;
use App\Helpers\MoneyHelper;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SyncScadenziarioWithFattura implements ShouldQueue
{
    
    use InteractsWithQueue;

    public function handle(FatturaRegistrata $event): void
    {
        $fattura    = $event->fattura;
        $userId     = $event->userId; 
        $condominio = $fattura->condominio;
        $fornitore  = $fattura->fornitore;

        $catAdmin = CategoriaEvento::where('name', 'Scadenze amministrative')->firstOrFail();

        $urlAzione = route('admin.gestionale.fatture.index', [
            'condominio' => $condominio->id,
            'search'     => $fattura->numero_documento,
        ]);

        // --- EVENTO PAGAMENTO ---
        $start = $fattura->data_scadenza->copy()->setTime(9, 0);

        Evento::updateOrCreate(
            [
                'meta->context->fattura_id' => $fattura->id,
                'meta->type'                => 'pagamento_fornitore',
            ],
            [
                'title'       => "Pagare {$fornitore->ragione_sociale} ({$condominio->nome})",
                'start_time'  => $start,
                'end_time'    => $start->copy()->addHour(),
                'created_by'  => $userId,
                'description' => "Scadenza fattura n. {$fattura->numero_documento}.\n Importo netto: ". MoneyHelper::format($fattura->netto_a_pagare),
                'category_id' => $catAdmin->id,
                'visibility'  => VisibilityStatus::HIDDEN->value ?? 'hidden',
                'is_approved' => true,
                'meta'        => [
                    'type'             => 'pagamento_fornitore',
                    'requires_action'  => ($fattura->stato_pagamento !== 'pagata'),
                    'is_completed'     => ($fattura->stato_pagamento === 'pagata'),
                    'condominio_nome'  => $condominio->nome,
                    'importo'          => $fattura->netto_a_pagare,
                    'fornitore'        => $fornitore->ragione_sociale,
                    'numero_documento' => $fattura->numero_documento,
                    'titolo_azione'    => 'Registra pagamento',
                    'action_url'       => $urlAzione,
                    'context'          => [
                        'fattura_id'   => $fattura->id,
                        'fornitore_id' => $fornitore->id,
                    ],
                ],
            ]
        )->condomini()->syncWithoutDetaching([$condominio->id]);

        // --- EVENTO RATIFICA SFORO (solo se la fattura è in sforo_motivato) ---
        // Ricorda all'amministratore che deve portare lo sforamento in assemblea.
        if ($fattura->stato_approvazione === 'sforo_motivato') {
            $scadenzaRatifica = now()->addDays(30)->setTime(9, 0);

            Evento::updateOrCreate(
                [
                    'meta->context->fattura_id' => $fattura->id,
                    'meta->type'                => 'ratifica_sforo',
                ],
                [
                    'title'       => "Ratifica assemblea - Sforo budget ({$condominio->nome})",
                    'start_time'  => $scadenzaRatifica,
                    'end_time'    => $scadenzaRatifica->copy()->addHour(),
                    'created_by'  => $userId,
                    'description' => "Portare in assemblea la ratifica dello sforamento budget.\nFattura: {$fattura->numero_documento} - {$fornitore->ragione_sociale}",
                    'category_id' => $catAdmin->id,
                    'visibility'  => VisibilityStatus::HIDDEN->value ?? 'hidden',
                    'is_approved' => true,
                    'meta'        => [
                        'type'            => 'ratifica_sforo',
                        'requires_action' => true,
                        'condominio_nome' => $condominio->nome,
                        'importo'         => $fattura->netto_a_pagare,
                        'titolo_azione'   => 'Vedi Fattura',
                        'action_url'      => $urlAzione,
                        'context'         => [
                            'fattura_id' => $fattura->id,
                            'is_ratifica' => true,
                        ],
                    ],
                ]
            )->condomini()->syncWithoutDetaching([$condominio->id]);
        }

        // --- EVENTO RITENUTA (solo se presente) ---
        // Lo sposteremo nel futuro listener FatturaPagata che creeremo nel modulo Tesoreria (V 1.11), così il sistema genererà l'evento dell'F24 solo nel momento in cui c'è l'effettivo esborso finanziario
     /*    if ($fattura->importo_ritenuta > 0) {
            $scadenzaRitenuta = $fattura->data_documento->copy()
                ->addMonth()
                ->day(16)
                ->setTime(9, 0);

            if ($scadenzaRitenuta->isWeekend()) {
                $scadenzaRitenuta->next('Monday')->setTime(9, 0);
            }

            Evento::updateOrCreate(
                [
                    'meta->context->fattura_id' => $fattura->id,
                    'meta->type'                => 'versamento_ritenuta',
                ],
                [
                    'title'       => "F24 Ritenuta - {$fornitore->ragione_sociale}",
                    'start_time'  => $scadenzaRitenuta,
                    'end_time'    => $scadenzaRitenuta->copy()->addHour(),
                    'created_by'  => $userId,
                    'description' => "Versare ritenuta Cod. {$fornitore->codice_tributo}.\nImporto: "
                                     . number_format($fattura->importo_ritenuta / 100, 2, ',', '.') . ' €',
                    'category_id' => $catAdmin->id,
                    'visibility'  => VisibilityStatus::HIDDEN->value ?? 'hidden',
                    'is_approved' => true,
                    'meta'        => [
                        'type'            => 'versamento_ritenuta',
                        'requires_action' => true,
                        'condominio_nome' => $condominio->nome,
                        'importo'         => $fattura->importo_ritenuta,
                        'titolo_azione'   => 'Vedi F24',
                        'action_url'      => $urlAzione,
                        'context'         => [
                            'fattura_id' => $fattura->id,
                            'is_f24'     => true,
                        ],
                    ],
                ]
            )->condomini()->syncWithoutDetaching([$condominio->id]);
        } */
    }
}
