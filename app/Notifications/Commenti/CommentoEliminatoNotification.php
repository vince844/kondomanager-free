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
 * L'avviso all'autore di un commento che un altro ha eliminato (decisioni 478 (2) e 483 (3)).
 *
 * Parte dopo l'eliminazione, in coda: il commento è già eliminato (soft delete) e l'elemento può non esserci più, quindi la
 * notifica non dipende dal model e non ha uno stato da riverificare: porta con sé il testo (data, titolo, inizio del commento,
 * se era in attesa). Per un commento in attesa dice «non è stato pubblicato».
 */
class CommentoEliminatoNotification extends LocalizedNotification implements ShouldQueue
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
        return $notifiable instanceof User;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $chiave = $this->chiaveAvviso();
        $sufisso = $this->eraInAttesa ? '_pending' : '';

        $mail = (new MailMessage)
            ->subject(__("{$chiave}.subject{$sufisso}"))
            ->line(__("{$chiave}.line_1{$sufisso}", ['data' => $this->data, 'entity' => $this->riferimento()]));

        if ($this->inizio !== '') {
            $mail->line(__("{$chiave}.started", ['inizio' => $this->inizio]));
        }

        return $this->conIlPulsante($mail, $notifiable);
    }

    protected function chiaveAvviso(): string
    {
        return "notifications.deleted_{$this->tipo}_comment";
    }
}
