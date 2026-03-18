<?php

namespace App\Listeners\Gestionale;

use App\Enums\VisibilityStatus;
use App\Events\Gestionale\FatturaRegistrata;
use App\Helpers\MoneyHelper;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Saldo;
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
        // --- EVENTO 1: CONVOCAZIONE IMMEDIATA (Obbligo Legale Art. 1135) ---
        if ($fattura->stato_approvazione === 'sforo_motivato') {
            $scadenzaConvocazione = now()->addDays(7)->setTime(9, 0);

            Evento::updateOrCreate(
                [
                    'meta->context->fattura_id' => $fattura->id,
                    'meta->type'                => 'convocazione_urgenza',
                ],
                [
                    'title'       => "Convocare Assemblea - Sforo Urgente ({$condominio->nome})",
                    'start_time'  => $scadenzaConvocazione,
                    'end_time'    => $scadenzaConvocazione->copy()->addHour(),
                    'created_by'  => $userId,
                    'description' => "Inviare convocazione per ratifica spesa urgente Art. 1135 c.c.\nFattura: {$fattura->numero_documento} - {$fornitore->ragione_sociale}",
                    'category_id' => $catAdmin->id,
                    'visibility'  => VisibilityStatus::HIDDEN->value,
                    'is_approved' => true,
                    'meta'        => [
                        'type'            => 'convocazione_urgenza',
                        'requires_action' => true,
                        'priority'        => 'high', // Priorità alta per l'invio
                        'titolo_azione'   => 'Prepara Convocazione',
                        'action_url'      => $urlAzione,
                        'context'         => ['fattura_id' => $fattura->id]
                    ],
                ]
            )->condomini()->syncWithoutDetaching([$condominio->id]);

            // --- EVENTO 2: RATIFICA IN ASSEMBLEA (Obiettivo Finale) ---
            $scadenzaRatifica = now()->addDays(30)->setTime(9, 0);

            Evento::updateOrCreate(
                [
                    'meta->context->fattura_id' => $fattura->id,
                    'meta->type'                => 'ratifica_sforo',
                ],
                [
                    'title'       => "Ratifica Assemblea - Sforo budget ({$condominio->nome})",
                    'start_time'  => $scadenzaRatifica,
                    'end_time'    => $scadenzaRatifica->copy()->addHour(),
                    'created_by'  => $userId,
                    'description' => "Verificare che la ratifica della fattura {$fattura->numero_documento} sia stata messa a verbale.",
                    'category_id' => $catAdmin->id,
                    'visibility'  => VisibilityStatus::HIDDEN->value,
                    'is_approved' => true,
                    'meta'        => [
                        'type'            => 'ratifica_sforo',
                        'requires_action' => true,
                        'titolo_azione'   => 'Vedi Dettaglio',
                        'action_url'      => $urlAzione,
                        'context'         => ['fattura_id' => $fattura->id]
                    ],
                ]
            )->condomini()->syncWithoutDetaching([$condominio->id]);
        }

        // --- 1. EVENTO EMISSIONE RATA PER SOPRAVVENIENZA ---
        // Se l'utente ha usato lo "Split" in Spesa Corrente, DEVE emettere le rate!
        $haSopravvenienza = $fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->exists();
        
        if ($haSopravvenienza) {
            $scadenzaRate = now()->addDays(7)->setTime(9, 0); // Promemoria tra 1 settimana

            Evento::updateOrCreate(
                [
                    'meta->context->fattura_id' => $fattura->id,
                    'meta->type'                => 'emissione_rata_sopravvenienza',
                ],
                [
                    'title'       => "Emettere Rate: Sopravvenienza Passiva ({$condominio->nome})",
                    'start_time'  => $scadenzaRate,
                    'end_time'    => $scadenzaRate->copy()->addHour(),
                    'created_by'  => $userId,
                    'description' => "Hai registrato una spesa imprevista (Sopravvenienza) per la fattura n. {$fattura->numero_documento} di {$fornitore->ragione_sociale}. Devi emettere un piano rate per riscuotere l'importo dai condòmini.",
                    'category_id' => $catAdmin->id,
                    'visibility'  => VisibilityStatus::HIDDEN->value ?? 'hidden', // Mostrato solo nell'Inbox
                    'is_approved' => true,
                    'meta'        => [
                        'type'            => 'emissione_rata_sopravvenienza',
                        'requires_action' => true,
                        'condominio_nome' => $condominio->nome,
                        'fornitore'       => $fornitore->ragione_sociale,
                        'titolo_azione'   => 'Crea Piano Rate',
                        'action_url' => route('admin.gestionale.esercizi.piani-rate.create', [
                            'condominio' => $condominio->id,
                            'esercizio'  => $fattura->esercizio_id
                        ]),
                        'context'         => [
                            'fattura_id' => $fattura->id,
                        ],
                    ],
                ]
            )->condomini()->syncWithoutDetaching([$condominio->id]);
        }

        // --- 2. EVENTO TAMPONE DEFICIT RATA 0 ---
        // Scatta SOLO se la fattura è pregressa E non abbiamo già creato una Sopravvenienza per coprire il buco
        if ($fattura->is_pregresso && $fattura->saldo_patrimoniale_id && !$haSopravvenienza) {
            
            $debitoIniziale = abs(Saldo::find($fattura->saldo_patrimoniale_id)->saldo_iniziale ?? 0);
            
            $rataZeroRichiesta = Saldo::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $fattura->esercizio_id)
                ->whereNotNull('anagrafica_id')
                ->where('saldo_iniziale', '>', 0)
                ->sum('saldo_iniziale');

            $bucoCents = max(0, $debitoIniziale - $rataZeroRichiesta);

            if ($bucoCents > 0) {
                $scadenzaDeficit = now()->addDays(15)->setTime(9, 0);

                Evento::updateOrCreate(
                    [
                        'meta->context->saldo_id' => $fattura->saldo_patrimoniale_id,
                        'meta->type'              => 'pianifica_ripianamento_deficit',
                    ],
                    [
                        'title'       => "Deficit Cassa Ereditato: {$fornitore->ragione_sociale}",
                        'start_time'  => $scadenzaDeficit,
                        'end_time'    => $scadenzaDeficit->copy()->addHour(),
                        'created_by'  => $userId,
                        // FORMATTAZIONE CORRETTA: Passiamo i centesimi al MoneyHelper
                        'description' => "Il debito ereditato ({$fornitore->ragione_sociale}) è scoperto per " . MoneyHelper::format($bucoCents) . ". Poiché non hai creato sopravvenienze, questi soldi mancheranno dal conto corrente ordinario. Valutare azione di recupero morosità pregresse.",
                        'category_id' => $catAdmin->id,
                        'visibility'  => VisibilityStatus::HIDDEN->value ?? 'hidden',
                        'is_approved' => true,
                        'meta'        => [
                            'type'            => 'pianifica_ripianamento_deficit',
                            'requires_action' => true,
                            'condominio_nome' => $condominio->nome,
                            'importo_buco'    => $bucoCents, // Salviamo sempre in centesimi
                            'titolo_azione'   => 'Gestisci Morosità',
                            'action_url'      => $urlAzione,
                            'context'         => [
                                'saldo_id' => $fattura->saldo_patrimoniale_id,
                            ],
                        ],
                    ]
                )->condomini()->syncWithoutDetaching([$condominio->id]);
            }
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
