<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class InviteUserNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    protected $invito;

    /**
     * Create a new notification instance.
     *
     * @param  int  $invitoId
     * @return void
     */
    public function __construct($invito)
    {
        // Important to load translations
        parent::__construct(); 
        $this->invito = $invito;
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
     * Get the notification's mail representation.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Firmato ma senza una scadenza sua: la scadenza è quella dell'invito, che il controller
        // controlla e spiega («l'invito è scaduto, chiedine un altro»). Con una scadenza nella firma
        // rispondeva prima il middleware `signed`, con il 403 generico, e il messaggio dell'invito
        // scaduto non si raggiungeva mai (1.11.0-beta.45). Un invito cancellato dà 404.
        $signedUrl = URL::signedRoute('invito.register', ['id' => $this->invito->id]);

        return (new MailMessage)

             ->subject(__('notifications.invite_user.subject', ['appName' => config('app.name')]))
            ->line(__('notifications.invite_user.line_1'))
            ->action(__('notifications.invite_user.action'), $signedUrl)
            ->line(__('notifications.invite_user.line_2'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
