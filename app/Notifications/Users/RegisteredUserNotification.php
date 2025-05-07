<?php

namespace App\Notifications\Users;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegisteredUserNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
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
            ->subject('Nuovo utente registrato')
            ->greeting('Salve ' . ($notifiable->name ?? $notifiable->nome))
            ->line("Un nuovo utente si è registrato sul portale. Dopo che avrà confermato il suo indirizzo email potra accedere all'area privata.")
            ->line("Assicurati di associare l'anagrafica a uno o più condomini se vuoi permette all'utente di visualizzare i dati di questi.")
            ->action('Accedi al portale', url(config('app.url')));
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
