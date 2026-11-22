<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use Symfony\Component\Mime\Email;

/**
 * Chi porta un messaggio PEC al server del gestore: di serie {@see TrasportoPecSmtp}, che si collega con l'utente e la password della casella dello studio. Nei
 * test si sostituisce con `app()->instance(TrasportoPec::class, …)`: nessuna PEC parte davvero.
 *
 * Il messaggio è accettato quando `manda()` torna senza errori; un rifiuto o una connessione che non parte è un'eccezione di Symfony Mailer
 * ({@see \Symfony\Component\Mailer\Exception\TransportExceptionInterface}).
 */
interface TrasportoPec
{
    public function manda(ConfigurazionePec $casella, Email $messaggio): void;
}
