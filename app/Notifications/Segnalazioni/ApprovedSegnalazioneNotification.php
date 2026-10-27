<?php

namespace App\Notifications\Segnalazioni;

use App\Enums\Permission;
use App\Helpers\RouteHelper;
use App\Models\Segnalazione;
use App\Notifications\Concerns\PulsanteSoloSeLaApre;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class ApprovedSegnalazioneNotification extends LocalizedNotification implements ShouldQueue
{
    use PulsanteSoloSeLaApre;
    use Queueable;
    use TestoScrittoDaAltri;

    public $segnalazione;
    public $user;

    /**
     * Create a new notification instance.
     */
    public function __construct(Segnalazione $segnalazione, $user)
    {
        // Important to load translations
        parent::__construct();
        $this->segnalazione = $segnalazione;
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
     * di una segnalazione non privata che non è più nel palazzo non la apre, e la mail lo dice.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $routePrefix = RouteHelper::getRoutePrefixForUser($notifiable);

        $mail = (new MailMessage)
            ->subject(__('notifications.approved_ticket.subject'))
            ->greeting(__('notifications.approved_ticket.greeting', [
                'name' => self::nomeComeTesto($notifiable->name ?? $notifiable->nome)
            ]))
            ->line(__('notifications.approved_ticket.line_1', [
                'user' => self::nomeComeTesto($this->user->name)
            ]))
            ->line('**' . __('notifications.approved_ticket.object') . ':** ' . self::testoComeTesto($this->segnalazione->subject))
            ->line('**' . __('notifications.approved_ticket.priority') . ':** ' . Str::ucfirst($this->segnalazione->priority));

        return $this->pulsanteOMotivo(
            $mail,
            $notifiable,
            $this->segnalazione,
            __('notifications.approved_ticket.action'),
            url("/{$routePrefix}/segnalazioni/{$this->segnalazione->id}"),
            Permission::VIEW_SEGNALAZIONI->value,
            (string) $this->segnalazione->condominio?->nome,
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
