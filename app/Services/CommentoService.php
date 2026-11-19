<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services;

use App\Contracts\Commentable;
use App\Enums\NotificationType;
use App\Events\Commenti\CommentoCreato;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Commenti\CommentoApprovatoNotification;
use App\Notifications\Commenti\CommentoEliminatoNotification;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use App\Notifications\Commenti\CommentoModificatoInAttesaNotification;
use App\Notifications\Commenti\CommentoNascostoNotification;
use App\Notifications\Commenti\NuovoCommentoNotification;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CommentoService
{
    /**
     * Crea un nuovo commento su un'entità commentabile.
     *
     * @throws \Throwable
     */
    public function crea(
        Commentable $commentable,
        User $autore,
        string $corpo,
        ?int $condominioId = null,
        ?int $discussioneUserId = null
    ): Commento {
        return DB::transaction(function () use ($commentable, $autore, $corpo, $condominioId, $discussioneUserId) {
            $stato = RegoleCommenti::statoAllaNascita($autore, $commentable);

            $effettivoCondominioId = $condominioId ?? $commentable->condominioId();

            $commento = $commentable->tuttiICommenti()->create([
                'user_id'             => $autore->id,
                'condominio_id'       => $effettivoCondominioId,
                'discussione_user_id' => $discussioneUserId,
                'corpo'               => $this->sanitizza($corpo),
                'stato'               => $stato,
            ]);

            event(new CommentoCreato($commento));

            return $commento;
        });
    }

    /**
     * Aggiorna il corpo di un commento e imposta modificato_at.
     * Se il commento era pubblicato e per chi modifica lo stato alla nascita sarebbe in_attesa,
     * torna in_attesa, notifica chi può approvare e sincronizza l'Inbox.
     *
     * @throws \Throwable
     */
    public function aggiorna(Commento $commento, string $nuovoCorpo, ?User $modificatore = null): bool
    {
        $modificatore = $modificatore ?? auth()->user();
        $eraPubblicato = ($commento->stato === 'pubblicato');
        $elemento = $commento->commentable;

        $tornaInAttesa = false;
        if ($eraPubblicato && $modificatore && $elemento) {
            $tornaInAttesa = (RegoleCommenti::statoAllaNascita($modificatore, $elemento) === 'in_attesa');
        }

        DB::transaction(function () use ($commento, $nuovoCorpo, $tornaInAttesa) {
            $data = [
                'corpo'         => $this->sanitizza($nuovoCorpo),
                'modificato_at' => now(),
            ];

            if ($tornaInAttesa) {
                $data['stato'] = 'in_attesa';
                $data['moderato_da'] = null;
                $data['moderato_at'] = null;
            }

            $commento->update($data);
        });

        if ($tornaInAttesa) {
            $destinatari = app(CommentoNotificationService::class)->destinatariModificatoInAttesa($commento);
            if ($destinatari->isNotEmpty()) {
                Notification::send($destinatari, new CommentoModificatoInAttesaNotification($commento));
            }
            app(CommentoEventoService::class)->sincronizza($commento);
        }

        return $tornaInAttesa;
    }

    /**
     * Approva un commento in_attesa, rendendolo visibile pubblicamente.
     * Traccia il moderatore e il timestamp.
     */
    public function approva(Commento $commento, User $moderatore): void
    {
        $commento->update([
            'stato'       => 'pubblicato',
            'moderato_da' => $moderatore->id,
            'moderato_at' => now(),
        ]);

        $commentable = $commento->commentable;

        // 1. Notifica autore del commento (se diverso dal moderatore)
        if ($commento->autore && $commento->autore->email && (int) $commento->autore->id !== (int) $moderatore->id) {
            $prefTipo = $commentable instanceof Segnalazione
                ? NotificationType::COMMENT_APPROVED->value
                : NotificationType::COMMUNICATION_COMMENT_APPROVED->value;

            if (app(CommentoNotificationService::class)->preferenzaAttiva($commento->autore, $prefTipo)) {
                $commento->autore->notify(new CommentoApprovatoNotification($commento));
            }
        }

        // 2. Notifica i partecipanti alla discussione (esclusi moderatore e autore del commento)
        $altriDestinatari = app(CommentoNotificationService::class)->destinatariPubblicato(
            $commento,
            [(int) $moderatore->id, (int) $commento->user_id]
        );

        if ($altriDestinatari->isNotEmpty()) {
            Notification::send($altriDestinatari, new NuovoCommentoNotification($commento));
        }
    }

    /**
     * Nasconde un commento (moderazione post-pubblicazione).
     * Il commento non viene eliminato ma diventa invisibile agli utenti normali.
     * Mostrare placeholder "Commento rimosso da un amministratore" in UI.
     */
    public function modera(Commento $commento, User $moderatore): void
    {
        $commento->update([
            'stato'       => 'nascosto',
            'moderato_da' => $moderatore->id,
            'moderato_at' => now(),
        ]);

        $commentable = $commento->commentable;

        if ($commento->autore && $commento->autore->email && (int) $commento->autore->id !== (int) $moderatore->id) {
            $prefTipo = $commentable instanceof Segnalazione
                ? NotificationType::COMMENT_HIDDEN->value
                : NotificationType::COMMUNICATION_COMMENT_HIDDEN->value;

            if (app(CommentoNotificationService::class)->preferenzaAttiva($commento->autore, $prefTipo)) {
                $commento->autore->notify(new CommentoNascostoNotification($commento));
            }
        }
    }

    /**
     * Elimina un commento (soft delete) e avvisa l'autore se a eliminarlo è un altro (decisioni 478 (2) e 483 (3)).
     *
     * L'avviso non parte se l'autore elimina il suo commento, se non ha l'email, se ha spento «Commento eliminato» o se non ha più il
     * permesso di commentare quel tipo; parte anche per un commento già nascosto, e per uno in attesa dice che non è stato
     * pubblicato. La notifica si compone prima dell'eliminazione, con il testo che serve, e va in coda.
     */
    public function elimina(Commento $commento, User $chi): void
    {
        $autore = $commento->autore;
        $elemento = $commento->commentable;
        $notifica = null;

        if (
            $autore && $autore->email
            && (int) $autore->id !== (int) $chi->id
            && ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione)
        ) {
            $prefTipo = $elemento instanceof Segnalazione
                ? NotificationType::COMMENT_REMOVED->value
                : NotificationType::COMMUNICATION_COMMENT_REMOVED->value;

            if (
                RegoleCommenti::haPermesso($autore, RegoleCommenti::permesso($elemento, 'commenta'))
                && app(CommentoNotificationService::class)->preferenzaAttiva($autore, $prefTipo)
            ) {
                $notifica = new CommentoEliminatoNotification($commento);
            }
        }

        $commento->delete();

        if ($notifica !== null) {
            $autore->notify($notifica);
        }
    }

    /**
     * Rimette visibile un commento nascosto (decisione 456). Nessuna email all'autore; la data del commento non cambia.
     */
    public function mostraDiNuovo(Commento $commento, User $moderatore): void
    {
        $commento->update([
            'stato'       => 'pubblicato',
            'moderato_da' => $moderatore->id,
            'moderato_at' => now(),
        ]);
    }

    /**
     * Sanitizza il corpo del commento: solo testo, niente HTML.
     * In futuro si può evolvere a markdown ristretto.
     */
    private function sanitizza(string $corpo): string
    {
        return trim(strip_tags($corpo));
    }
}
