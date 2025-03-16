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

        // Generate a signed URL (valid for 60 minutes) WITHOUT token
        $resetUrl = URL::temporarySignedRoute(
            'password.new',
            Carbon::now()->addMinutes(60),
            ['email' => $this->user->email]
        );

        return (new MailMessage)
            ->subject('Benvenuto su KondoManager')
            ->greeting("Ciao {$this->user->name},")
            ->line("L'amministratore di condominio ha creato il tuo profilo. Clicca sul seguente link per impostare la tua password.")
            ->action('Imposta password', $resetUrl)
            ->line('Questo link scadrà in 60 minuti.');
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
