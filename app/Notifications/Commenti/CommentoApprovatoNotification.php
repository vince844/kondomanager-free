<?php

namespace App\Notifications\Commenti;

use App\Models\Commento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use App\Helpers\RouteHelper;

class CommentoApprovatoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Commento $commento) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entita = class_basename($this->commento->commentable);
        $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
        
        return (new MailMessage)
                    ->subject("Il tuo commento è stato approvato")
                    ->line("Il tuo commento sulla {$entita} è stato approvato e pubblicato.")
                    ->action('Visualizza Segnalazione', url("/{$prefix}/segnalazioni/{$this->commento->commentable_id}#commenti"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'commento_id'      => $this->commento->id,
            'commentable_type' => $this->commento->commentable_type,
            'commentable_id'   => $this->commento->commentable_id,
            'anteprima'        => Str::limit($this->commento->corpo, 120),
        ];
    }
}
