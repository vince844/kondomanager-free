<?php

namespace App\Notifications\Segnalazioni;

use App\Helpers\RouteHelper;
use App\Models\Segnalazione;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/** Gemella di `UpdatedComunicazioneNotification`: vedi lì il perché di una classe a parte. */
class UpdatedSegnalazioneNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    public $segnalazione;

    public function __construct(Segnalazione $segnalazione)
    {
        parent::__construct();
        $this->segnalazione = $segnalazione;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $routePrefix = RouteHelper::getRoutePrefixForUser($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.updated_ticket.subject'))
            ->greeting(__('notifications.updated_ticket.greeting', [
                'name' => $notifiable->name ?? $notifiable->nome,
            ]))
            ->line(__('notifications.updated_ticket.line_1', [
                'user' => $this->segnalazione->updatedBy?->name ?? $this->segnalazione->createdBy->name,
            ]))
            ->line('**' . __('notifications.updated_ticket.object') . ':** ' . $this->segnalazione->subject)
            ->line('**' . __('notifications.updated_ticket.status') . ':** ' . Str::ucfirst($this->segnalazione->stato))
            ->action(
                __('notifications.updated_ticket.action'),
                url("/{$routePrefix}/segnalazioni/{$this->segnalazione->id}")
            );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
