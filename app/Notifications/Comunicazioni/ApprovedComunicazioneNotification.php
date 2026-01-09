<?php

namespace App\Notifications\Comunicazioni;

use App\Models\Comunicazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use App\Helpers\RouteHelper;
use App\Notifications\LocalizedNotification;

class ApprovedComunicazioneNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    public $comunicazione;
    public $user;

    /**
     * Create a new notification instance.
     */
    public function __construct(Comunicazione $comunicazione, $user)
    {
        // Important to load translations
        parent::__construct(); 
        $this->comunicazione = $comunicazione;
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
        
        $routePrefix = RouteHelper::getRoutePrefixForUser($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.approved_communication.subject'))
            ->greeting(__('notifications.approved_communication.greeting', [
                'name' => $notifiable->name ?? $notifiable->nome
            ]))
            ->line(__('notifications.approved_communication.line_1', [
                'user' => $this->user->name
            ]))
            ->line(
                '**' . __('notifications.approved_communication.object') . ':** ' .
                $this->comunicazione->subject
            )
            ->line(
                '**' . __('notifications.approved_communication.priority') . ':** ' .
                Str::ucfirst($this->comunicazione->priority)
            )
            ->action(
                __('notifications.approved_communication.action'),
                url("/{$routePrefix}/comunicazioni/{$this->comunicazione->id}")
            );
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
