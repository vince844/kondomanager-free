<?php

namespace App\Notifications\Documenti;

use App\Helpers\RouteHelper;
use App\Models\Documento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ApproveDocumentoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $documento;

    /**
     * Create a new notification instance.
     */
    public function __construct(Documento $documento)
    {
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
            ->subject('Nuovo documento archivio da approvare')
            ->greeting('Salve ' . ($notifiable->name ?? $notifiable->nome))
            ->line("L'utente ". $this->documento->createdBy->name ." ha creato un nuovo documento in archivio del condominio")
            ->line("Il documento è in attesa di essere approvato perchè l'utente che l'ho inviato non ha permessi sufficienti per pubblicarlo")
            ->line('**Titolo:** ' . $this->documento->name)
            ->line('**Descrizione:** ' . Str::ucfirst($this->documento->description))
            ->action('Visualizza documenti', url("/{$routePrefix}/documenti/"));
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
