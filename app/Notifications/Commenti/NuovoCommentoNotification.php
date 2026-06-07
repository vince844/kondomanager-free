<?php

namespace App\Notifications\Commenti;

use App\Models\Commento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use App\Helpers\RouteHelper;

class NuovoCommentoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Commento $commento) {}

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

        return (new MailMessage)
            ->subject("Nuovo commento su: {$titoloEntita}")
            ->greeting("Salve!")
            ->line("{$autoreNome} ha lasciato un nuovo commento:")
            ->line(Str::limit($this->commento->corpo, 200))
            ->action('Visualizza la segnalazione', $url)
            ->line('Ricevi questa email perché sei coinvolto in questa segnalazione.');
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
