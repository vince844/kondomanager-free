<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Il trasporto di serie della PEC: SMTP verso il server del gestore, con la cifratura della casella: SSL è la cifratura dall'inizio (la porta 465, quella dei
 * gestori PEC), TLS è STARTTLS, obbligatorio: se il server non lo offre la PEC non parte in chiaro. Un collegamento solo per messaggio, aperto e chiuso
 * qui, con un tempo massimo di 20 secondi: dove la porta in uscita è chiusa l'errore arriva presto, e la pagina lo dice con parole semplici
 * ({@see ErroreDellaPec}).
 */
final class TrasportoPecSmtp implements TrasportoPec
{
    public const SECONDI = 20;

    public function manda(ConfigurazionePec $casella, Email $messaggio): void
    {
        $trasporto = new EsmtpTransport($casella->server, $casella->porta, $casella->cifratura === 'ssl');
        if ($casella->cifratura !== 'ssl') {
            $trasporto->setRequireTls(true);
        }
        $trasporto->setUsername($casella->utente);
        $trasporto->setPassword($casella->password());
        $flusso = $trasporto->getStream();
        if (method_exists($flusso, 'setTimeout')) {
            $flusso->setTimeout(self::SECONDI);
        }
        try {
            $trasporto->send($messaggio);
        } finally {
            $trasporto->stop();
        }
    }
}
