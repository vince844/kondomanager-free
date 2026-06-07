<?php

namespace App\Notifications\Commenti;

use App\Models\Commento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use App\Helpers\RouteHelper;

class CommentoInAttesaNotification extends Notification implements ShouldQueue
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
                    ->subject("Nuovo commento da moderare su {$entita}")
                    ->line("{$this->commento->autore->name} ha scritto un commento che richiede approvazione.")
                    ->action('Visualizza Segnalazione', url("/{$prefix}/segnalazioni/{$this->commento->commentable_id}#commenti"))
                    ->line('Accedi per approvare o rifiutare il commento.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'commento_id'      => $this->commento->id,
            'commentable_type' => $this->commento->commentable_type,
            'commentable_id'   => $this->commento->commentable_id,
            'autore'           => $this->commento->autore->name ?? 'Sconosciuto',
            'anteprima'        => Str::limit($this->commento->corpo, 120),
        ];
    }
}
