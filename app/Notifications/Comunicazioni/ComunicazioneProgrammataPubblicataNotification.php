<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Comunicazioni;

use App\Models\Comunicazione;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Support\Comunicazioni\PubblicazioneProgrammata;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

class ComunicazioneProgrammataPubblicataNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    public function __construct(
        public Comunicazione $comunicazione,
        public DateTimeInterface $programmataPer,
        public DateTimeInterface $pubblicataIl
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

    public function toMail(object $notifiable): MailMessage
    {
        $fuso = PubblicazioneProgrammata::fuso();
        $sceltaLocale = Carbon::instance($this->programmataPer)->setTimezone($fuso);
        $uscitaLocale = Carbon::instance($this->pubblicataIl)->setTimezone($fuso);

        $title = self::testoComeTesto($this->comunicazione->subject);

        $mail = (new MailMessage)
            ->subject(__('notifications.scheduled_communication_published.subject', ['title' => $title]))
            ->line(__('notifications.scheduled_communication_published.line_1', [
                'title' => $title,
                'date'  => $sceltaLocale->format('d/m/Y'),
                'time'  => $sceltaLocale->format('H:i'),
            ]));

        if ($uscitaLocale->getTimestamp() - $sceltaLocale->getTimestamp() > 300) {
            $mail->line(__('notifications.scheduled_communication_published.line_late', [
                'date' => $uscitaLocale->format('d/m/Y'),
                'time' => $uscitaLocale->format('H:i'),
            ]));
        }

        $mail->action(
            __('notifications.scheduled_communication_published.action'),
            route('admin.comunicazioni.show', $this->comunicazione)
        );

        return $mail;
    }
}
