<?php

namespace App\Services;

use App\Contracts\Commentable;
use App\Enums\Permission;
use App\Events\Commenti\CommentoCreato;
use App\Models\Commento;
use App\Models\User;
use App\Notifications\Commenti\CommentoApprovatoNotification;
use App\Notifications\Commenti\CommentoEliminatoNotification;
use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

class CommentoService
{
    /**
     * Crea un nuovo commento su un'entità commentabile.
     *
     * Il workflow di approvazione è basato sui permessi (stile CMS):
     * - Con PUBLISH_COMMENTS_SEGNALAZIONI → stato 'pubblicato' (visibile subito)
     * - Senza quel permesso → stato 'in_attesa' (richiede approvazione admin)
     *
     * @throws \Throwable
     */
    public function crea(Commentable $commentable, User $autore, string $corpo): Commento
    {
        return DB::transaction(function () use ($commentable, $autore, $corpo) {
            $forceModeration = app(\App\Settings\GeneralSettings::class)->force_comment_moderation;

            if ($forceModeration && !$autore->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)) {
                $stato = 'in_attesa';
            } else {
                $stato = $autore->hasPermissionTo(Permission::PUBLISH_COMMENTS_SEGNALAZIONI->value)
                    ? 'pubblicato'
                    : 'in_attesa';
            }

            $commento = $commentable->tuttiICommenti()->create([
                'user_id'       => $autore->id,
                'condominio_id' => $commentable->condominioId(),
                'corpo'         => $this->sanitizza($corpo),
                'stato'         => $stato,
            ]);

            event(new CommentoCreato($commento));

            return $commento;
        });
    }

    /**
     * Aggiorna il corpo di un commento e imposta modificato_at.
     *
     * @throws \Throwable
     */
    public function aggiorna(Commento $commento, string $nuovoCorpo): void
    {
        DB::transaction(function () use ($commento, $nuovoCorpo) {
            $commento->update([
                'corpo'         => $this->sanitizza($nuovoCorpo),
                'modificato_at' => now(),
            ]);
        });
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

        if ($commento->autore && $commento->autore->email) {
            $wantsNotification = NotificationPreference::where('user_id', $commento->autore->id)
                ->where('type', NotificationType::COMMENT_APPROVED->value)
                ->value('enabled') ?? false;

            if ($wantsNotification) {
                $commento->autore->notify(new CommentoApprovatoNotification($commento));
            }
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

        if ($commento->autore && $commento->autore->email) {
            $wantsNotification = NotificationPreference::where('user_id', $commento->autore->id)
                ->where('type', NotificationType::COMMENT_DELETED->value)
                ->value('enabled') ?? false;

            if ($wantsNotification) {
                $commento->autore->notify(new CommentoEliminatoNotification($commento));
            }
        }
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
