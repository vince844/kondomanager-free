<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Listeners\Gestionale;

use App\Enums\StatoPagamentoFattura;
use App\Enums\VisibilityStatus;
use App\Events\Gestionale\FatturaRegistrata;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Saldo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SyncScadenziarioWithFattura implements ShouldQueue
{
    
    use InteractsWithQueue;

    // Aggiungi questa proprietà!
    public bool $afterCommit = true;

    public function handle(FatturaRegistrata $event): void
    {
        $fattura    = $event->fattura;
        $userId     = $event->userId; 
        $condominio = $fattura->condominio;
        $fornitore  = $fattura->fornitore;

        $catAdmin = CategoriaEvento::where('name', 'Scadenze amministrative')->firstOrFail();

        // I collegamenti dei compiti si salvano come percorso: non dipendono dall'indirizzo del sito.
        $urlAzione = route('admin.gestionale.fatture.index', [
            'condominio' => $condominio->id,
            'search'     => $fattura->numero_documento,
        ], false);

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
                    'requires_action'  => ($fattura->stato_pagamento !== StatoPagamentoFattura::PAGATA),
                    'is_completed'     => ($fattura->stato_pagamento === StatoPagamentoFattura::PAGATA),
                    'condominio_nome'  => $condominio->nome,
                    'importo'          => $fattura->netto_a_pagare,
                    'fornitore'        => $fornitore->ragione_sociale,
                    'numero_documento' => $fattura->numero_documento,
                    'titolo_azione'    => 'Registra pagamento',
                    'action_url'       => route('admin.gestionale.pagamenti-fornitori.create', [
                        'condominio' => $condominio->id,
                        'fattura_id' => $fattura->id,
                    ], false),
                    'context'          => [
                        'fattura_id'   => $fattura->id,
                        'fornitore_id' => $fornitore->id,
                    ],
                ],
            ]
        )->condomini()->syncWithoutDetaching([$condominio->id]);

        // --- EVENTO RATIFICA SFORO (solo se la fattura è in sforo_motivato) ---
        // I giorni dei compiti qui sotto partono dal giorno di Roma, scritti come l'agenda scrive le ore (`DateHelper::adessoComeSiLegge`): fra le 00:00 e
        // l'01:00 di Roma (le 02:00 d'estate) `now()` è ancora ieri, e il compito nasceva un giorno prima.
        // --- EVENTO 1: CONVOCAZIONE IMMEDIATA (Obbligo Legale Art. 1135) ---
        if ($fattura->stato_approvazione === 'sforo_motivato') {
            $scadenzaConvocazione = DateHelper::adessoComeSiLegge()->addDays(7)->setTime(9, 0);

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
            $scadenzaRatifica = DateHelper::adessoComeSiLegge()->addDays(30)->setTime(9, 0);

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
            $scadenzaRate = DateHelper::adessoComeSiLegge()->addDays(7)->setTime(9, 0); // Promemoria tra 1 settimana

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
                        ], false),
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
            
            // Stessa lettura del widget Double Lock, e stesso difetto: `anagrafica_id` non
            // separa i pregressi dei condòmini dai debiti verso fornitori, `fornitore_id` sì.
            // Con il filtro vecchio i pregressi intestati all'unità non entravano nel conto e
            // il «buco» risultava più grande del reale. Corretta insieme alla gemella nella
            // beta.43: la lezione della beta.40 è che si corregge la classe, non il caso.
            $rataZeroRichiesta = Saldo::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $fattura->esercizio_id)
                ->whereNull('fornitore_id')
                // B2 (S5): la coppia del conguaglio di un passaggio somma zero e non è provvista — la gamba
                // positiva da sola gonfierebbe la rata 0 (stessa esclusione di FatturaPassivaController). Lo stesso
                // per le righe legate a un passaggio (1.11.0-beta.53).
                ->whereNull('subentro_id')
                ->whereNull('passaggio_id')
                ->where('saldo_iniziale', '>', 0)
                ->sum('saldo_iniziale');

            $bucoCents = max(0, $debitoIniziale - $rataZeroRichiesta);

            if ($bucoCents > 0) {
                $scadenzaDeficit = DateHelper::adessoComeSiLegge()->addDays(15)->setTime(9, 0);

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
                        // Lo scoperto è la parte del debito che i pregressi chiesti ai condòmini non coprono: nessuna rata la chiede, e sollecitare le rate scadute
                        // non la porta. La descrizione dice ciò che il compito chiede; i compiti già scritti restano come sono.
                        'description' => "Il debito ereditato ({$fornitore->ragione_sociale}) è scoperto per " . MoneyHelper::format($bucoCents) . ": è la parte che i pregressi chiesti ai condòmini non coprono, e nessuna rata la chiede. Poiché non hai creato sopravvenienze, questi soldi mancheranno dal conto corrente ordinario. Si ripiana con un piano rate che chieda ai condòmini la cifra che manca: «Prepara il piano di ripianamento».",
                        'category_id' => $catAdmin->id,
                        'visibility'  => VisibilityStatus::HIDDEN->value ?? 'hidden',
                        'is_approved' => true,
                        'meta'        => [
                            'type'            => 'pianifica_ripianamento_deficit',
                            'requires_action' => true,
                            'condominio_nome' => $condominio->nome,
                            'importo_buco'    => $bucoCents, // Salviamo sempre in centesimi
                            // Il deficit si ripiana con un piano rate straordinario del condominio: l'azione porta alla sua creazione (la mappa dei compiti la apre
                            // nell'esercizio aperto di oggi).
                            'titolo_azione'   => 'Prepara il piano di ripianamento',
                            'action_url'      => route('admin.gestionale.esercizi.piani-rate.create', ['condominio' => $condominio->id, 'esercizio' => $fattura->esercizio_id,
                                'tipo' => 'straordinario'], false),
                            'context'         => [
                                'saldo_id' => $fattura->saldo_patrimoniale_id,
                            ],
                        ],
                    ]
                )->condomini()->syncWithoutDetaching([$condominio->id]);
            }
        }

        // NOTA: il task F24/Ritenuta NON viene creato qui.
        // La ritenuta matura fiscalmente al momento del PAGAMENTO, non della fattura.
        // Il task viene creato da SyncF24WithPagamento listener su evento PagamentoRegistrato.
    }
}
