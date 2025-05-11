<?php

namespace App\Notifications\Segnalazioni;

use App\Helpers\RouteHelper;
use App\Models\Segnalazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ApprovedSegnalazioneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $segnalazione;
    public $user;

    /**
     * Create a new notification instance.
     */
    public function __construct(Segnalazione $segnalazione, $user)
    {
         $this->segnalazione = $segnalazione;
         $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $routePrefix = RouteHelper::getRoutePrefixForUser($notifiable);

        return (new MailMessage)
            ->subject('Nuova segnalazione guasto approvata')
            ->greeting('Salve ' . ($notifiable->name ?? $notifiable->nome))
            ->line("L'utente ". $this->user->name ." ha approvato la segnalazione guasto")
            ->line('**Oggetto:** ' . $this->segnalazione->subject)
            ->line('**Priorità:** ' . Str::ucfirst($this->segnalazione->priority))
            ->action('Visualizza segnalazione', url("/{$routePrefix}/segnalazioni/" . $this->segnalazione->id));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
