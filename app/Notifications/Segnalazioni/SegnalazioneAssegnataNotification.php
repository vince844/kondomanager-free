<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Segnalazioni;

use App\Models\Segnalazione;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class SegnalazioneAssegnataNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Segnalazione $segnalazione,
        public string $chiLHaAssegnata,
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $segnalazione = Segnalazione::find($this->segnalazione->id);

        return $segnalazione !== null && (int) $segnalazione->assigned_to === (int) $notifiable->id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.assigned_ticket.subject', [
                'title' => self::testoComeTesto($this->segnalazione->subject),
            ]))
            ->line(__('notifications.assigned_ticket.line_1', [
                'user' => self::nomeComeTesto($this->chiLHaAssegnata),
                'title' => self::testoComeTesto($this->segnalazione->subject),
                'building' => self::nomeComeTesto($this->segnalazione->condominio?->nome),
            ]))
            ->action(__('notifications.assigned_ticket.action'), route('admin.segnalazioni.show', $this->segnalazione));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}

