<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Allegati;

use App\Enums\EventoTipo;
use App\Models\Comunicazione;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Gestionale\InboxService;

final class AllegatoEventoService
{
    public function sincronizza(Segnalazione|Comunicazione $elemento, ?User $autore = null): void
    {
        $inAttesa = $elemento->allegati()->where('is_approved', false)->count();

        $evento = Evento::query()
            ->where('tipo', EventoTipo::ALLEGATO->value)
            ->where('eventable_type', $elemento->getMorphClass())
            ->where('eventable_id', $elemento->getKey())
            ->where('is_completed', false)
            ->first();

        if ($inAttesa === 0) {
            if ($evento !== null) {
                $evento->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);
            }

            return;
        }

        $priorita = in_array($elemento->priority ?? null, ['alta', 'urgente'], true) ? 'alta' : 'normale';
        $titolo = $elemento->subject ?? (class_basename($elemento) . ' #' . $elemento->getKey());
        $nomeAutore = $autore?->anagrafica?->nome ?: ($autore?->name ?? 'Utente');
        $condominioId = $elemento instanceof Segnalazione
            ? $elemento->condominio_id
            : $elemento->condomini()->value('condomini.id');
        $description = "Allegati in attesa di approvazione per \"{$titolo}\".";
        // Il percorso, non l'indirizzo intero: il collegamento non dipende dall'indirizzo del sito.
        $actionUrl = route(
            $elemento instanceof Segnalazione ? 'admin.segnalazioni.show' : 'admin.comunicazioni.show',
            $elemento,
            false,
        );

        if ($evento !== null) {
            $meta = is_array($evento->meta) ? $evento->meta : [];
            // Il momento dell'ultimo arrivo, solo quando gli allegati in attesa crescono: un'approvazione li fa scendere, e non è una cosa nuova. Lo legge
            // la regola dei compiti in attesa di «Ricordamelo» (`Evento::scopeCompitiAperti()`).
            $arrivo = $inAttesa > (int) ($meta['contatore'] ?? 0) ? ['ultimo_arrivo' => now()->utc()->format('Y-m-d H:i:s')] : [];
            $evento->update([
                'description' => $description,
                'priorita'    => $priorita,
                'meta'        => array_merge($meta, [
                    'action_url'    => $actionUrl,
                    'contatore'     => $inAttesa,
                    'ultimo_autore' => $nomeAutore,
                ], $arrivo),
                'updated_at'  => now(),
            ]);

            return;
        }

        InboxService::createTask(
            tipo: EventoTipo::ALLEGATO,
            title: 'Allegati da approvare — ' . $titolo,
            description: $description,
            // Due giorni dal giorno di Roma, scritti come l'agenda scrive le ore.
            scadenza: \App\Helpers\DateHelper::adessoComeSiLegge()->addDays(2),
            createdByUserId: $autore?->id,
            condominioId: $condominioId !== null ? (int) $condominioId : null,
            context: [],
            actionUrl: $actionUrl,
            extraMeta: [
                'contatore'     => $inAttesa,
                'ultimo_autore' => $nomeAutore,
                'ultimo_arrivo' => now()->utc()->format('Y-m-d H:i:s'),
            ],
            eventableType: $elemento->getMorphClass(),
            eventableId: $elemento->getKey(),
            priorita: $priorita,
        );
    }

    public function chiudiPerElemento(Segnalazione|Comunicazione $elemento): void
    {
        Evento::query()
            ->where('tipo', EventoTipo::ALLEGATO->value)
            ->where('eventable_type', $elemento->getMorphClass())
            ->where('eventable_id', $elemento->getKey())
            ->where('is_completed', false)
            ->update([
                'is_completed' => true,
                'completed_at' => now(),
            ]);
    }
}

