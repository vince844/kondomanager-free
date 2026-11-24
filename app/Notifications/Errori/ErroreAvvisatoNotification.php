<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Errori;

use App\Models\Errori\VoltaDellErrore;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Support\Errori\RapportoDellErrore;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * L'email «Errore avvisato» (decisione 701): a chi ha «Gestisci impostazioni generali» e ha acceso la voce nelle sue preferenze, quando un condòmino o un
 * collaboratore avvisa lo studio di un errore dalla pagina d'errore. Dice il minimo: il codice, l'ora, la pagina (con i segnaposto della rotta), chi ha
 * avvisato e «Apri l'errore». **Niente dettaglio tecnico né la nota di chi avvisa**: l'email esce dal server, e la nota l'ha scritta una persona. Il resto è
 * nella scheda «Errori».
 *
 * **Una per errore** (Coda 788, decisione 713): parte solo con l'avviso che fa nascere il compito della Action inbox; gli avvisi dopo, a compito aperto, si
 * contano nel compito senza un'altra email.
 *
 * Parte subito (non dalla coda): l'avviso ha già il suo limite, uno per codice e cinque all'ora per persona.
 */
class ErroreAvvisatoNotification extends LocalizedNotification
{
    use Queueable;
    use TestoScrittoDaAltri;

    /** La preferenza di notifica (`NotificationType::ERROR_REPORTED`). */
    public const TIPO = 'error_reported';

    public function __construct(
        public VoltaDellErrore $volta,
        public string $chiHaAvvisato,
        public ?string $ruolo,
    ) {
        parent::__construct();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $codice = $this->volta->codice;
        $pagina = $this->volta->pagina !== null ? trim(($this->volta->metodo ?? '').' '.$this->volta->pagina) : (string) ($this->volta->azione ?? '—');

        return (new MailMessage)
            ->subject(__('notifications.errore_avvisato.subject', ['codice' => $codice, 'nome' => self::nomeComeTesto($this->chiHaAvvisato)]))
            ->line(__('notifications.errore_avvisato.line_1', [
                'nome' => self::nomeComeTesto($this->chiHaAvvisato),
                'ruolo' => RapportoDellErrore::chiPerLaScheda($this->ruolo, true),
                'quando' => RapportoDellErrore::quando($this->volta->avvenuto_il, false),
            ]))
            ->line(__('notifications.errore_avvisato.line_codice', ['codice' => $codice]))
            ->line(__('notifications.errore_avvisato.line_pagina', ['pagina' => $pagina]))
            ->action(__('notifications.errore_avvisato.action'), route('logs.index', ['scheda' => 'errori', 'cerca' => $codice]))
            ->line(__('notifications.errore_avvisato.line_dettaglio'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
