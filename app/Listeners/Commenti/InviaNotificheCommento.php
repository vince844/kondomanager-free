<?php

namespace App\Listeners\Commenti;

use App\Events\Commenti\CommentoCreato;
use App\Notifications\NuovoCommentoNotification;
use App\Services\CommentoNotificationService;
use Illuminate\Support\Facades\Notification;

class InviaNotificheCommento
{
    public function __construct(private CommentoNotificationService $service) {}

    /**
     * Invia la notifica ai destinatari pertinenti quando viene creato un commento.
     * L'autore del commento non viene mai notificato.
     */
    public function handle(CommentoCreato $evento): void
    {
        $destinatari = $this->service->destinatari($evento->commento);

        if ($destinatari->isEmpty()) {
            return;
        }

        Notification::send($destinatari, new NuovoCommentoNotification($evento->commento));
    }
}
