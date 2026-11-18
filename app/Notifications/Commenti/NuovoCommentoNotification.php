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

class NuovoCommentoNotification extends LocalizedNotification implements ShouldQueue
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
        if (! $commento || $commento->stato !== 'pubblicato') {
            return false;
        }

        $commentable = $commento->commentable;
        if (! $commentable) {
            return false;
        }

        // Un commento scritto da un palazzo che nel frattempo è stato tolto dai destinatari non si vede, per nessuno (decisione 459).
        if (! RegoleCommenti::restaFraIDestinatari($commento, $commentable)) {
            return false;
        }

        $chiaveDiscussione = RegoleCommenti::chiaveDelCommento($commento, $commentable);

        $porta = RegoleCommenti::haPannello($notifiable) ? 'admin' : 'user';
        $visibili = RegoleCommenti::discussioniVisibili($notifiable, $commentable, $porta);

        return $visibili->pluck('chiave')->contains($chiaveDiscussione);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $autoreNome = $this->commento->autore?->anagrafica?->nome
            ?? $this->commento->autore?->name
            ?? 'Utente';

        $commentable = $this->commento->commentable;
        $titoloEntita = $commentable?->subject
            ?? ($commentable instanceof Comunicazione ? 'Comunicazione' : 'Segnalazione');

        $url = $this->urlCommentabile($notifiable);
        $key = $this->getEntityKey();

        $subjectKey = "notifications.new_{$key}_comment.subject";
        $greetingKey = "notifications.new_{$key}_comment.greeting";
        $line1Key = "notifications.new_{$key}_comment.line_1";
        $actionKey = "notifications.new_{$key}_comment.action";
        $line2Key = "notifications.new_{$key}_comment.line_2";

        return (new MailMessage)
            ->subject(__($subjectKey, ['entity' => $titoloEntita]))
            ->greeting(__($greetingKey))
            ->line(__($line1Key, ['user' => self::nomeComeTesto($autoreNome)]))
            ->line(Str::limit(self::testoComeTesto($this->commento->corpo), 200))
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

    public function toArray(mixed $notifiable): array
    {
        return [
            'commento_id'      => $this->commento->id,
            'commentable_type' => $this->commento->commentable_type,
            'commentable_id'   => $this->commento->commentable_id,
            'autore'           => $this->commento->autore?->anagrafica?->nome
                                    ?? $this->commento->autore?->name,
            'anteprima'        => Str::limit($this->commento->corpo, 120),
        ];
    }

    private function urlCommentabile(mixed $notifiable): string
    {
        try {
            $commentable = $this->commento->commentable;
            $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
            if ($commentable instanceof Comunicazione) {
                return route("{$prefix}.comunicazioni.show", $commentable) . '#commenti';
            }
            if ($commentable instanceof Segnalazione) {
                return route("{$prefix}.segnalazioni.show", $commentable) . '#commenti';
            }
        } catch (\Throwable) {
            // fallback silenzioso
        }

        return url('/');
    }
}
