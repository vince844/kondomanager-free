<?php

namespace App\Notifications\Commenti;

use App\Models\Commento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\LocalizedNotification;

class CommentoEliminatoNotification extends LocalizedNotification implements ShouldQueue
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
        $commentable = $this->commento->commentable;
        $entita = class_basename($commentable);
        
        $titolo = $commentable->subject ?? $commentable->titolo ?? $commentable->title ?? $commentable->nome ?? null;
        $riferimento = $titolo ? "{$entita} \"{$titolo}\"" : $entita;
        $key = $this->getEntityKey();

        $subjectKey = "notifications.deleted_{$key}_comment.subject";
        $line1Key = "notifications.deleted_{$key}_comment.line_1";

        return (new MailMessage)
                    ->subject(__($subjectKey))
                    ->line(__($line1Key, [
                        'entity' => $riferimento
                    ]));
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
        ];
    }
}
