<?php

namespace App\Notifications\Documenti;

use App\Helpers\RouteHelper;
use App\Models\Documento;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class NewDocumentoNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

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
        return (new MailMessage)
            ->subject(__('notifications.new_document.subject'))
            ->greeting(__('notifications.new_document.greeting', [
                'name' => self::nomeComeTesto($notifiable->name ?? $notifiable->nome)
            ]))
            ->line(__('notifications.new_document.line_1', [
                'user' => self::nomeComeTesto($this->documento->createdBy->name)
            ]))
            ->line('**' . __('notifications.new_document.title') . ':** ' . self::testoComeTesto($this->documento->name))
            ->line('**' . __('notifications.new_document.description') . ':** ' . Str::ucfirst(self::testoComeTesto($this->documento->description)))
            ->action(__('notifications.new_document.action'), RouteHelper::urlArchivioDocumenti($notifiable));

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
