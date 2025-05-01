<?php

namespace App\Notifications;

use App\Models\Comunicazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewAdminComunicazioneNotification extends Notification
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
        return (new MailMessage)
            ->subject('Nuova comunicazione da approvare')
            ->greeting('Salve ' . $notifiable->nome)
            ->line("Una nuova comunicazione è stata creata. La comunicazione è in attesa di essere approvata perchè l'utente che l'ha inviata non ha permessi sufficienti per pubblicarla")
            ->line('**Oggetto:** ' . $this->comunicazione->subject)
            ->line('**Priorità:** ' . Str::ucfirst($this->comunicazione->priority))
            ->action('Visualizza comunicazione', url('/admin/comunicazioni/' . $this->comunicazione->id));
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
