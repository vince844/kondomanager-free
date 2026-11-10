<?php

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
        $actionUrl = route(
            $elemento instanceof Segnalazione ? 'admin.segnalazioni.show' : 'admin.comunicazioni.show',
            $elemento,
        );

        if ($evento !== null) {
            $meta = is_array($evento->meta) ? $evento->meta : [];
            $evento->update([
                'description' => $description,
                'priorita'    => $priorita,
                'meta'        => array_merge($meta, [
                    'action_url'    => $actionUrl,
                    'contatore'     => $inAttesa,
                    'ultimo_autore' => $nomeAutore,
                ]),
                'updated_at'  => now(),
            ]);

            return;
        }

        InboxService::createTask(
            tipo: EventoTipo::ALLEGATO,
            title: 'Allegati da approvare — ' . $titolo,
            description: $description,
            scadenza: now()->addDays(2),
            createdByUserId: $autore?->id,
            condominioId: $condominioId !== null ? (int) $condominioId : null,
            context: [],
            actionUrl: $actionUrl,
            extraMeta: [
                'contatore'     => $inAttesa,
                'ultimo_autore' => $nomeAutore,
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

