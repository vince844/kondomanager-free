<?php

namespace App\Notifications\Users;

use App\Notifications\Concerns\TestoScrittoDaAltri;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegisteredUserNotification extends Notification
{
    use Queueable;
    // Il nome dell'amministratore nel saluto, che può aver scritto un altro amministratore: arriva come testo, come
    // nelle altre mail (decisione 136.3).
    use TestoScrittoDaAltri;

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
        $name = self::nomeComeTesto($notifiable->name ?? $notifiable->nome ?? '');

        return (new MailMessage)
        
            ->subject(__('notifications.new_user_registered.subject'))
            ->greeting(__('notifications.new_user_registered.greeting', ['name' => $name]))
            ->line(__('notifications.new_user_registered.line_1'))
            ->line(__('notifications.new_user_registered.line_2'))
            ->action(__('notifications.new_user_registered.action'), url(config('app.url')));
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
