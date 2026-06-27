<?php

namespace App\Services;

use App\Models\Commento;
use App\Models\Evento;
use App\Enums\EventoTipo;
use App\Services\Gestionale\InboxService;
use Illuminate\Support\Str;

class CommentoEventoService
{
    /**
     * Sincronizza l'Admin Inbox per il commento appena creato.
     * Crea un nuovo evento o aggiorna quello esistente se aperto.
     *
     * @param Commento $commento
     * @return void
     */
    public function sincronizza(Commento $commento): void
    {
        $commentable = $commento->commentable;
        if (!$commentable) return;

        // Usa i metodi di default forniti dal contratto Commentable / trait HasComments
        $priorita    = method_exists($commentable, 'prioritaCommento') ? $commentable->prioritaCommento() : 'normale';
        $titolo      = method_exists($commentable, 'titoloInbox') ? $commentable->titoloInbox() : class_basename($commentable) . ' #' . $commentable->getKey();
        $anteprima   = Str::limit($commento->corpo, 100);
        $autore      = $commento->autore->name ?? 'Utente sconosciuto';

        $stato = $commento->stato ?? 'pubblicato';
        if ($stato === 'in_attesa') {
            $description = "L'utente {$autore} ha inviato un commento nella segnalazione \"{$titolo}\" che richiede di essere moderato o approvato.";
        } else {
            $description = "L'utente {$autore} ha inviato un nuovo commento nella segnalazione \"{$titolo}\".";
        }

        // Cerca un Evento aperto per questo thread
        $evento = Evento::query()
            ->where('tipo',             'commento')
            ->where('eventable_type',   $commento->commentable_type)
            ->where('eventable_id',     $commento->commentable_id)
            ->where('visibility',       'hidden')
            ->where('is_completed',     false)
            ->first();

        if ($evento) {
            // Aggiorna l'evento esistente
            $meta = is_array($evento->meta) ? $evento->meta : [];
            $evento->update([
                'description' => $description, // Aggiorniamo la descrizione
                'priorita'   => $priorita, // scala la priorità se l'entità è diventata urgente
                'meta'       => array_merge($meta, [
                    'contatore'        => ($meta['contatore'] ?? 0) + 1,
                    'ultima_anteprima' => $anteprima,
                    'ultimo_autore'    => $autore,
                ]),
                'updated_at' => now(),
            ]);
        } else {
            // Crea un nuovo evento tramite Builder Inbox
            InboxService::createTask(
                tipo: EventoTipo::COMMENTO,
                title: 'Nuovo commento — ' . $titolo,
                description: $description,
                scadenza: now()->addDays(2),
                createdByUserId: $commento->user_id,
                condominioId: $commento->condominio_id,
                context: [
                    'commento_id' => $commento->id,
                ],
                actionUrl: null, // l'admin cliccherà sul dettaglio dalla UI, se presente
                extraMeta: [
                    'contatore'        => 1,
                    'ultima_anteprima' => $anteprima,
                    'ultimo_autore'    => $autore,
                ],
                eventableType: $commento->commentable_type,
                eventableId: $commento->commentable_id,
                priorita: $priorita
            );
        }
    }
}
