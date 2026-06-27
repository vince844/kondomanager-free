<?php

namespace App\Listeners\Commenti;

use App\Events\Commenti\CommentoCreato;
use App\Notifications\Commenti\NuovoCommentoNotification;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use App\Models\User;
use App\Models\NotificationPreference;
use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Services\CommentoNotificationService;
use App\Services\CommentoEventoService;
use Illuminate\Support\Facades\Notification;

class InviaNotificheCommento
{
    public function __construct(
        private CommentoNotificationService $service,
        private CommentoEventoService $inbox
    ) {}

    /**
     * Invia la notifica ai destinatari pertinenti quando viene creato un commento.
     * L'autore del commento non viene mai notificato.
     */
    public function handle(CommentoCreato $evento): void
    {
        $commento = $evento->commento;

        if ($commento->eInAttesa()) {
            // Notifica gli amministratori se il commento richiede moderazione
            $admins = User::permission(Permission::APPROVE_COMMENTS_SEGNALAZIONI->value)->get();
            
            $enabledAdmins = NotificationPreference::whereIn('user_id', $admins->pluck('id'))
                ->where('type', NotificationType::COMMENT_UNDER_MODERATION->value)
                ->where('enabled', true)
                ->pluck('user_id')
                ->toArray();
            
            $adminsDaNotificare = $admins->filter(fn ($u) => in_array($u->id, $enabledAdmins) && $u->email);
            
            if ($adminsDaNotificare->isNotEmpty()) {
                Notification::send($adminsDaNotificare, new CommentoInAttesaNotification($commento));
            }
        } else {
            // Flusso normale: notifica i partecipanti
            $destinatari = $this->service->destinatari($commento);

            if ($destinatari->isNotEmpty()) {
                Notification::send($destinatari, new NuovoCommentoNotification($commento));
            }
        }

        // Canale 2: Admin Inbox (Evento aggregato) per l'amministratore
        $this->inbox->sincronizza($commento);
    }
}
