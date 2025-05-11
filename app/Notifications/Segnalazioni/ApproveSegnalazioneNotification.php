<?php

namespace App\Notifications\Segnalazioni;

use App\Helpers\RouteHelper;
use App\Models\Segnalazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ApproveSegnalazioneNotification extends Notification implements ShouldQueue
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
        $routePrefix = RouteHelper::getRoutePrefixForUser($notifiable);

        return (new MailMessage)
            ->subject('Nuova segnalazione da approvare')
            ->greeting('Salve ' . ($notifiable->name ?? $notifiable->nome))
            ->line("L'utente ". $this->segnalazione->createdBy->name ." ha creato una nuova segnalazione guasto per il condominio")
            ->line("La segnalazione è in attesa di essere approvata perchè l'utente che l'ha inviata non ha permessi sufficienti per pubblicarla")
            ->line('**Oggetto:** ' . $this->segnalazione->subject)
            ->line('**Priorità:** ' . Str::ucfirst($this->segnalazione->priority))
            ->line('**Stato:** ' . Str::ucfirst($this->segnalazione->stato))
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
