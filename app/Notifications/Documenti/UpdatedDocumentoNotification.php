<?php

namespace App\Notifications\Documenti;

use App\Helpers\RouteHelper;
use App\Models\Documento;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/** Gemella di `UpdatedComunicazioneNotification`: vedi lì il perché di una classe a parte. */
class UpdatedDocumentoNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    public $documento;

    public function __construct(Documento $documento)
    {
        parent::__construct();
        $this->documento = $documento;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.updated_document.subject'))
            ->greeting(__('notifications.updated_document.greeting', [
                'name' => $notifiable->name ?? $notifiable->nome,
            ]))
            ->line(__('notifications.updated_document.line_1', [
                'user' => $this->documento->updatedBy?->name ?? $this->documento->createdBy->name,
            ]))
            ->line('**' . __('notifications.updated_document.title') . ':** ' . $this->documento->name)
            ->line('**' . __('notifications.updated_document.description') . ':** ' . Str::ucfirst((string) $this->documento->description))
            ->action(
                __('notifications.updated_document.action'),
                RouteHelper::urlArchivioDocumenti($notifiable)
            );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
