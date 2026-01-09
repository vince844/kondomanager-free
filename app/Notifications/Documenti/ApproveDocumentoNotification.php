<?php

namespace App\Notifications\Documenti;

use App\Helpers\RouteHelper;
use App\Models\Documento;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class ApproveDocumentoNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    public $documento;

    /**
     * Create a new notification instance.
     */
    public function __construct(Documento $documento)
    {
        // Important to load translations
        parent::__construct();
        $this->documento = $documento;
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
            ->subject(__('notifications.approve_document.subject'))
            ->greeting(__('notifications.approve_document.greeting', [
                'name' => $notifiable->name ?? $notifiable->nome
            ]))
            ->line(__('notifications.approve_document.line_1', [
                'user' => $this->documento->createdBy->name
            ]))
            ->line(__('notifications.approve_document.line_2'))
            ->line('**' . __('notifications.approve_document.title') . ':** ' . $this->documento->name)
            ->line('**' . __('notifications.approve_document.description') . ':** ' . Str::ucfirst($this->documento->description))
            ->action(__('notifications.approve_document.action'), url("/{$routePrefix}/documenti/"));

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
