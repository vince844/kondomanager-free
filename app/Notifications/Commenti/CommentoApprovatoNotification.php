<?php

namespace App\Notifications\Commenti;

use App\Enums\Permission;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Notifications\Concerns\PulsanteSoloSeLaApre;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use Illuminate\Support\Str;
use App\Helpers\RouteHelper;

class CommentoApprovatoNotification extends LocalizedNotification implements ShouldQueue
{
    use PulsanteSoloSeLaApre;
    use Queueable;
    use TestoScrittoDaAltri;

    public function __construct(public Commento $commento) {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Il pulsante verso la scheda c'è solo se chi ha scritto il commento la apre ancora
     * (`PulsanteSoloSeLaApre`): fra il commento e l'approvazione può essere uscito dal palazzo, la
     * segnalazione può essere stata indirizzata ad altre persone o tornata in bozza. Altrimenti la mail
     * dice perché non la vede.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $commentable = $this->commento->commentable;
        $entita = class_basename($commentable);
        
        $titolo = $commentable->subject ?? $commentable->titolo ?? $commentable->title ?? $commentable->nome ?? null;
        $titolo = $titolo === null ? null : self::testoComeTesto($titolo);
        $riferimento = $titolo ? "{$entita} \"{$titolo}\"" : $entita;

        $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
        $key = $this->getEntityKey();
        
        $subjectKey = "notifications.approved_{$key}_comment.subject";
        $line1Key   = "notifications.approved_{$key}_comment.line_1";
        $actionKey  = "notifications.approved_{$key}_comment.action";
        
        $mail = (new MailMessage)
                    ->subject(__($subjectKey))
                    ->line(__($line1Key, [
                        'entity' => $riferimento
                    ]));

        $comunicazione = $commentable instanceof Comunicazione;

        return $this->pulsanteOMotivo(
            $mail,
            $notifiable,
            $commentable,
            __($actionKey),
            url("/{$prefix}/segnalazioni/{$this->commento->commentable_id}#commenti"),
            $comunicazione ? Permission::VIEW_COMUNICAZIONI->value : Permission::VIEW_SEGNALAZIONI->value,
            $comunicazione
                ? $commentable->condomini()->orderBy('nome')->pluck('nome')->implode(', ')
                : (string) $commentable->condominio?->nome,
        );
    }

    private function getEntityKey(): string
    {
        $class = class_basename($this->commento->commentable);
        return match($class) {
            'Segnalazione' => 'ticket',
            'Comunicazione' => 'post',
            default => strtolower($class),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'commento_id'      => $this->commento->id,
            'commentable_type' => $this->commento->commentable_type,
            'commentable_id'   => $this->commento->commentable_id,
            'anteprima'        => Str::limit($this->commento->corpo, 120),
        ];
    }
}
