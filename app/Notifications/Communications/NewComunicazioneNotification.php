<?php

namespace App\Notifications\Communications;

use App\Helpers\RouteHelper;
use App\Models\Comunicazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewComunicazioneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $comunicazione;

    /**
     * Create a new notification instance.
     */
    public function __construct(Comunicazione $comunicazione)
    {
        $this->comunicazione = $comunicazione;
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
            ->subject('Nuova comunicazione in bacheca')
            ->greeting('Salve ' . ($notifiable->name ?? $notifiable->nome))
            ->line("L'utente ". $this->comunicazione->createdBy->name ." ha creato una nuova comunicazione nella bacheca del condominio.")
            ->line('**Oggetto:** ' . $this->comunicazione->subject)
            ->line('**Priorità:** ' . Str::ucfirst($this->comunicazione->priority))
            ->action('Visualizza comunicazione', url("/{$routePrefix}/comunicazioni/" . $this->comunicazione->id));;
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
