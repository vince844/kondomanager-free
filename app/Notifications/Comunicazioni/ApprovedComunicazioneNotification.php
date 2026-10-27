<?php

namespace App\Notifications\Comunicazioni;

use App\Enums\Permission;
use App\Models\Comunicazione;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use App\Helpers\RouteHelper;
use App\Notifications\Concerns\PulsanteSoloSeLaApre;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;

class ApprovedComunicazioneNotification extends LocalizedNotification implements ShouldQueue
{
    use PulsanteSoloSeLaApre;
    use Queueable;
    use TestoScrittoDaAltri;

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
     *
     * Il pulsante verso la scheda c'è solo se il creatore la apre (`PulsanteSoloSeLaApre`): l'autore
     * di una comunicazione non privata che non è più in nessuno dei suoi palazzi non la apre, e la
     * mail lo dice.
     */
    public function toMail(object $notifiable): MailMessage
    {
        
        $routePrefix = RouteHelper::getRoutePrefixForUser($notifiable);

        $mail = (new MailMessage)
            ->subject(__('notifications.approved_communication.subject'))
            ->greeting(__('notifications.approved_communication.greeting', [
                'name' => self::nomeComeTesto($notifiable->name ?? $notifiable->nome)
            ]))
            ->line(__('notifications.approved_communication.line_1', [
                'user' => self::nomeComeTesto($this->user->name)
            ]))
            ->line(
                '**' . __('notifications.approved_communication.object') . ':** ' .
                self::testoComeTesto($this->comunicazione->subject)
            )
            ->line(
                '**' . __('notifications.approved_communication.priority') . ':** ' .
                Str::ucfirst($this->comunicazione->priority)
            );

        return $this->pulsanteOMotivo(
            $mail,
            $notifiable,
            $this->comunicazione,
            __('notifications.approved_communication.action'),
            url("/{$routePrefix}/comunicazioni/{$this->comunicazione->id}"),
            Permission::VIEW_COMUNICAZIONI->value,
            $this->comunicazione->condomini()->orderBy('nome')->pluck('nome')->implode(', '),
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
