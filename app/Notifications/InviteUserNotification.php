<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

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
        $signedUrl = URL::temporarySignedRoute(
            'invito.register',
            Carbon::now()->addMinutes(60),
            ['email' => $this->invito->email] 
        );

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
