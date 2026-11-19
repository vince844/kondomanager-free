<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Commenti;

use App\Models\Commento;
use App\Models\User;
use App\Notifications\Concerns\AvvisoSulCommentoDiUnAltro;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * L'avviso all'autore di un commento che l'amministratore ha nascosto (decisione 483 (3): oggi si chiama come ciò che fa; prima
 * «eliminato o nascosto»). Nasconde senza eliminare: il commento c'è ancora e a chi lo moderava si mostra di nuovo. Se prima della
 * mail viene mostrato di nuovo, la mail non parte.
 */
class CommentoNascostoNotification extends LocalizedNotification implements ShouldQueue
{
    use AvvisoSulCommentoDiUnAltro;
    use Queueable;

    public function __construct(Commento $commento)
    {
        parent::__construct();

        $this->ricordaIlCommento($commento);
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

        $commento = Commento::find($this->commentoId);

        return $commento !== null && $commento->stato === 'nascosto';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $chiave = $this->chiaveAvviso();

        $mail = (new MailMessage)
            ->subject(__("{$chiave}.subject"))
            ->line(__("{$chiave}.line_1", ['data' => $this->data, 'entity' => $this->riferimento()]));

        return $this->conIlPulsante($mail, $notifiable);
    }

    protected function chiaveAvviso(): string
    {
        return "notifications.hidden_{$this->tipo}_comment";
    }
}
