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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class CommentoEliminatoNotification extends LocalizedNotification implements ShouldQueue
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
        if (! $commento || $commento->stato !== 'nascosto') {
            return false;
        }

        return true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $commentable = $this->commento->commentable;
        $entita = $commentable instanceof Comunicazione ? 'comunicazione' : 'segnalazione';

        $titolo = $commentable->subject ?? $commentable->titolo ?? $commentable->title ?? $commentable->nome ?? null;
        $titolo = $titolo === null ? null : self::testoComeTesto($titolo);
        $riferimento = $titolo ? "{$entita} \"{$titolo}\"" : $entita;
        $key = $this->getEntityKey();

        $subjectKey = "notifications.deleted_{$key}_comment.subject";
        $line1Key = "notifications.deleted_{$key}_comment.line_1";

        return (new MailMessage)
            ->subject(__($subjectKey))
            ->line(__($line1Key, [
                'entity' => $riferimento,
            ]));
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
        ];
    }
}
