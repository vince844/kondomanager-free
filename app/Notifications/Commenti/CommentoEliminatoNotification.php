<?php

namespace App\Notifications\Commenti;

use App\Models\Commento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentoEliminatoNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
                    ->subject("Un tuo commento è stato rimosso")
                    ->line("Il tuo commento sulla {$entita} è stato rimosso o nascosto da un amministratore.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'commento_id'      => $this->commento->id,
            'commentable_type' => $this->commento->commentable_type,
            'commentable_id'   => $this->commento->commentable_id,
        ];
    }
}
