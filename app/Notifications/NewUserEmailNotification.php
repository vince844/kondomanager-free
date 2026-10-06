<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

class NewUserEmailNotification extends Notification 
{
    use Queueable;

    protected $user;

    /**
     * Create a new notification instance.
     */
    public function __construct($user)
    {
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

        $resetUrl = self::link($this->user);

        return (new MailMessage)

            ->subject(__('notifications.new_user_created.subject', ['appName' => config('app.name')]))
            ->greeting(__('notifications.new_user_created.greeting', ['name' => $this->user->name]))
            ->line(__('notifications.new_user_created.line_1'))
            ->action(__('notifications.new_user_created.action'), $resetUrl)
            ->line(__('notifications.new_user_created.line_2'));
    }

    /**
     * Il link del primo accesso: firmato, valido tre giorni, con l'impronta dello stato dell'utente
     * (`User::improntaPrimoAccesso()`). Lo costruisce solo questo metodo, così il link della mail e
     * quello dei test sono lo stesso.
     */
    public static function link($user, ?Carbon $scadenza = null): string
    {
        return URL::temporarySignedRoute('password.new', $scadenza ?? Carbon::now()->addDays(3), [
            'id'   => $user->id,
            'hash' => $user->improntaPrimoAccesso(),
        ]);
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
