<?php

namespace App\Notifications\Commenti;

use App\Models\Commento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\LocalizedNotification;
use Illuminate\Support\Str;
use App\Helpers\RouteHelper;

class NuovoCommentoNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Commento $commento) {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $autoreNome = $this->commento->autore?->anagrafica?->nome
            ?? $this->commento->autore?->name
            ?? 'Utente';

        $commentable = $this->commento->commentable;
        $titoloEntita = $commentable?->subject
            ?? class_basename($commentable)
            ?? 'Segnalazione';

        // Construisce l'URL alla segnalazione
        $url = $this->urlCommentabile($notifiable);
        $key = $this->getEntityKey();

        $subjectKey = "notifications.new_{$key}_comment.subject";
        $greetingKey = "notifications.new_{$key}_comment.greeting";
        $line1Key = "notifications.new_{$key}_comment.line_1";
        $actionKey = "notifications.new_{$key}_comment.action";
        $line2Key = "notifications.new_{$key}_comment.line_2";

        return (new MailMessage)
            ->subject(__($subjectKey, ['entity' => $titoloEntita]))
            ->greeting(__($greetingKey))
            ->line(__($line1Key, ['user' => $autoreNome]))
            ->line(Str::limit($this->commento->corpo, 200))
            ->action(__($actionKey), $url)
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

    public function toArray(mixed $notifiable): array
    {
        return [
            'commento_id'      => $this->commento->id,
            'commentable_type' => $this->commento->commentable_type,
            'commentable_id'   => $this->commento->commentable_id,
            'autore'           => $this->commento->autore?->anagrafica?->nome
                                    ?? $this->commento->autore?->name,
            'anteprima'        => Str::limit($this->commento->corpo, 120),
        ];
    }

    private function urlCommentabile(mixed $notifiable): string
    {
        try {
            $commentable = $this->commento->commentable;
            if ($commentable instanceof \App\Models\Segnalazione) {
                $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
                return url("/{$prefix}/segnalazioni/{$commentable->id}#commenti");
            }
        } catch (\Exception) {
            // fallback silenzioso
        }

        return url('/');
    }
}
