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

class SegnalazioneNonPiuAssegnataNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Segnalazione $segnalazione,
        public string $chiHaFattoIlGesto,
        public ?string $nuovoAssegnatario = null,
        public bool $presaPerSe = false,
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

        return $segnalazione !== null && (int) $segnalazione->assigned_to !== (int) $notifiable->id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->nuovoAssegnatario === null) {
            $line = __('notifications.unassigned_ticket.line_1_nobody', [
                'user'  => self::nomeComeTesto($this->chiHaFattoIlGesto),
                'title' => self::testoComeTesto($this->segnalazione->subject),
            ]);
        } elseif ($this->presaPerSe) {
            $line = __('notifications.unassigned_ticket.line_1_self', [
                'user'  => self::nomeComeTesto($this->chiHaFattoIlGesto),
                'title' => self::testoComeTesto($this->segnalazione->subject),
            ]);
        } else {
            $line = __('notifications.unassigned_ticket.line_1', [
                'user'  => self::nomeComeTesto($this->chiHaFattoIlGesto),
                'name'  => self::nomeComeTesto($this->nuovoAssegnatario),
                'title' => self::testoComeTesto($this->segnalazione->subject),
            ]);
        }

        return (new MailMessage)
            ->subject(__('notifications.unassigned_ticket.subject', [
                'title' => self::testoComeTesto($this->segnalazione->subject),
            ]))
            ->line($line)
            ->action(__('notifications.unassigned_ticket.action'), route('admin.segnalazioni.show', $this->segnalazione));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}

