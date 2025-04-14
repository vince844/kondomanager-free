<?php

namespace App\Notifications;

use App\Models\Segnalazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewAdminSegnalazioneNotification extends Notification implements ShouldQueue
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
        
     /*    $isAdminOrCollaboratore = false;

        // Check if it's a User and has the right role
        if ($notifiable instanceof \App\Models\User) {
            $isAdminOrCollaboratore = $notifiable->hasRole(['amministratore', 'collaboratore']);
        }

        $routePrefix = $isAdminOrCollaboratore ? 'admin' : 'user'; */

        return (new MailMessage)
        ->subject('Nuova segnalazione guasto da approvare')
        ->greeting('Salve ' . $notifiable->nome)
        ->line("Una nuova segnalazione guasto è stata creata. La segnalazione è in attesa di essere approvata perchè l'utente che l'ha inviata non ha permessi sufficienti per pubblicarla")
        ->line('**Oggetto:** ' . $this->segnalazione->subject)
        ->line('**Priorità:** ' . Str::ucfirst($this->segnalazione->priority))
        ->line('**Stato:** ' . Str::ucfirst($this->segnalazione->stato))
        ->action('Visualizza segnalazione', url('/admin/segnalazioni/' . $this->segnalazione->id));
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
