<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Commenti;

use App\Helpers\RouteHelper;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class CommentoInAttesaNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    public function __construct(public Commento $commento)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        $commento = Commento::find($this->commento->id);
        if (! $commento || $commento->stato !== 'in_attesa') {
            return false;
        }

        // Un commento scritto da un palazzo che nel frattempo è stato tolto dai destinatari non si vede, nemmeno nel compito
        // «Da moderare» (decisione 459): l'email non lo annuncia.
        return RegoleCommenti::restaFraIDestinatari($commento)
            && RegoleCommenti::puoApprovare($notifiable, $commento);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $commentable = $this->commento->commentable;
        $entita = $commentable instanceof Comunicazione ? 'Comunicazione' : 'Segnalazione';
        $titolo = method_exists($commentable, 'titoloInbox')
            ? $commentable->titoloInbox()
            : ($commentable->subject ?? "#{$this->commento->commentable_id}");

        $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
        $key = $this->getEntityKey();

        $subjectKey = "notifications.pending_{$key}_comment.subject";
        $line1Key = "notifications.pending_{$key}_comment.line_1";
        $actionKey = "notifications.pending_{$key}_comment.action";
        $line2Key = "notifications.pending_{$key}_comment.line_2";

        $url = $commentable instanceof Comunicazione
            ? route("{$prefix}.comunicazioni.show", $commentable) . '#commenti'
            : route("{$prefix}.segnalazioni.show", $commentable) . '#commenti';

        return (new MailMessage)
            ->subject(__($subjectKey, ['entity' => $entita]))
            ->line(__($line1Key, [
                'user'  => self::nomeComeTesto($this->commento->autore->name),
                'title' => self::testoComeTesto($titolo),
            ]))
            ->action(__($actionKey), $url)
            ->line(__($line2Key));
    }

    private function getEntityKey(): string
    {
        $class = class_basename($this->commento->commentable);

        return match ($class) {
            'Segnalazione'  => 'ticket',
            'Comunicazione' => 'communication',
            default         => strtolower($class),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'commento_id'      => $this->commento->id,
            'commentable_type' => $this->commento->commentable_type,
            'commentable_id'   => $this->commento->commentable_id,
            'autore'           => $this->commento->autore->name ?? 'Sconosciuto',
            'anteprima'        => Str::limit($this->commento->corpo, 120),
        ];
    }
}
