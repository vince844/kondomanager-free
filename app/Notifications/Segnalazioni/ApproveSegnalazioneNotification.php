<?php

namespace App\Notifications\Segnalazioni;

use App\Helpers\RouteHelper;
use App\Models\Segnalazione;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class ApproveSegnalazioneNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    public $segnalazione;

    /**
     * Create a new notification instance.
     */
    public function __construct(Segnalazione $segnalazione)
    {
        // Important to load translations
        parent::__construct();
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
            ->subject(__('notifications.approve_ticket.subject'))
            ->greeting(__('notifications.approve_ticket.greeting', [
                'name' => $notifiable->name ?? $notifiable->nome
            ]))
            ->line(__('notifications.approve_ticket.line_1', [
                'user' => $this->segnalazione->createdBy->name
            ]))
            ->line(__('notifications.approve_ticket.line_2'))
            ->line('**' . __('notifications.approve_ticket.object') . ':** ' . $this->segnalazione->subject)
            ->line('**' . __('notifications.approve_ticket.priority') . ':** ' . Str::ucfirst($this->segnalazione->priority))
            ->line('**' . __('notifications.approve_ticket.status') . ':** ' . Str::ucfirst($this->segnalazione->stato))
            ->action(__('notifications.approve_ticket.action'), url("/{$routePrefix}/segnalazioni/{$this->segnalazione->id}"));

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
