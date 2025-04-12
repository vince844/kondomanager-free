<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewSegnalazioneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $segnalazione;

    /**
     * Create a new notification instance.
     */
    public function __construct(Segnalazione $segnalazione)
    {
        $this->segnalazione = $segnalazione;
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
        return (new MailMessage)
        ->subject('Nuova segnalazione guasto')
        ->greeting('Salve ' . $notifiable->nome)
        ->line('Una nuova segnalazione guasto è stata creata.')
        ->line('Oggetto: ' . $this->segnalazione->subject)
        ->line('Priorità: ' . Str::ucfirst($this->segnalazione->priority))
        ->line('Stato: ' . Str::ucfirst($this->segnalazione->stato))
        ->action('Visualizza segnalazione', url('/user/segnalazioni/' . $this->segnalazione->id));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [

        ];
    }
}
