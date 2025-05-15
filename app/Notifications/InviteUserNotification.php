<?php

namespace App\Notifications;

use App\Models\Invito;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

class InviteUserNotification extends Notification implements ShouldQueue
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
            ->subject('Benvenuto su '.config('app.name'))
            ->line("L'amministratore di condominio ti ha invitato a registrare il tuo account online")
            ->action('Registrati adesso', $signedUrl)
            ->line('Questo invito sadrà tra tre giorni.');
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
