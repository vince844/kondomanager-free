<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Commenti;

use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\User;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class CommentoModificatoInAttesaNotification extends LocalizedNotification implements ShouldQueue
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
        $titolo = method_exists($commentable, 'titoloInbox')
            ? $commentable->titoloInbox()
            : ($commentable->subject ?? "#{$this->commento->commentable_id}");

        $url = $commentable instanceof Comunicazione
            ? route('admin.comunicazioni.show', $commentable) . '#commenti'
            : route('admin.segnalazioni.show', $commentable) . '#commenti';

        $key = $this->getEntityKey();

        $autore = self::nomeComeTesto($this->commento->autore?->name ?? __('commenti.autore_sconosciuto'));
        $titoloFormattato = self::testoComeTesto($titolo);

        return (new MailMessage)
            ->subject(__("notifications.edited_pending_{$key}_comment.subject"))
            ->line(__("notifications.edited_pending_{$key}_comment.line_1", [
                'user'  => $autore,
                'title' => $titoloFormattato,
            ]))
            ->line('"' . self::testoComeTesto($this->commento->corpo) . '"')
            ->action(__("notifications.edited_pending_{$key}_comment.action"), $url)
            ->line(__("notifications.edited_pending_{$key}_comment.line_2"));
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
            'autore'           => $this->commento->autore?->name ?? __('commenti.autore_sconosciuto'),
            'anteprima'        => Str::limit($this->commento->corpo, 120),
        ];
    }
}
