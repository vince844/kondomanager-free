<?php

namespace App\Notifications\Commenti;

use App\Models\Commento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\LocalizedNotification;
use Illuminate\Support\Str;
use App\Helpers\RouteHelper;

class CommentoInAttesaNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Commento $commento) {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entita = class_basename($this->commento->commentable);
        $titolo = method_exists($this->commento->commentable, 'titoloInbox') 
            ? $this->commento->commentable->titoloInbox() 
            : "#{$this->commento->commentable_id}";
            
        $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
        $key = $this->getEntityKey();
        
        $subjectKey = "notifications.pending_{$key}_comment.subject";
        $line1Key = "notifications.pending_{$key}_comment.line_1";
        $actionKey = "notifications.pending_{$key}_comment.action";
        $line2Key = "notifications.pending_{$key}_comment.line_2";

        return (new MailMessage)
                    ->subject(__($subjectKey, ['entity' => $entita]))
                    ->line(__($line1Key, [
                        'user' => $this->commento->autore->name,
                        'title' => $titolo
                    ]))
                    ->action(__($actionKey), url("/{$prefix}/segnalazioni/{$this->commento->commentable_id}#commenti"))
                    ->line(__($line2Key));
    }

    private function getEntityKey(): string
    {
        $class = class_basename($this->commento->commentable);
        return match($class) {
            'Segnalazione' => 'ticket',
            'Comunicazione' => 'post',
            default => strtolower($class),
        };
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
