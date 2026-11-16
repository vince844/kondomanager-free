<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Allegati;

use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class AllegatoEliminatoNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    public function __construct(
        public string $oggettoElemento,
        public string $nomeFile,
    ) {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $titolo = self::testoComeTesto($this->oggettoElemento);
        $file = self::nomeComeTesto($this->nomeFile);

        return (new MailMessage)
            ->subject("Allegato eliminato — «{$titolo}»")
            ->greeting('Ciao!')
            ->line("L'allegato «{$file}» in «{$titolo}» è stato eliminato.");
    }
}

