<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use App\Models\MailLog;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * «Prova l'invio» della casella PEC dello studio (decisione 572, proposte 5, 8 e 17): un messaggio breve, di serie verso la stessa casella PEC, dove arrivano
 * la ricevuta di accettazione e quella di consegna. Passa dallo stesso trasporto degli invii veri ({@see TrasportoPec}, nei test uno finto), con i valori
 * scritti nella pagina anche se non sono salvati.
 *
 * Ogni prova, andata o no, è una riga dello storico delle email (`mail_logs`, «pec» come modo di invio), con il testo del server se non è andata; il motivo
 * va anche nel registro del programma, senza la password. Che cosa ricordare della prova lo decide chi la chiama ({@see ProveDelleCaselle}).
 */
final class ProvaDellaPec
{
    public const OGGETTO = 'Prova della casella PEC dello studio';

    public const TESTO = 'Questo è un messaggio di prova della casella PEC dello studio. Se lo ricevi, e nella casella arrivano la ricevuta di accettazione '
        . 'e quella di consegna, la casella è pronta per le convocazioni per PEC.';

    /** Il modo di invio nello storico delle email. */
    public const NELLO_STORICO = 'pec';

    private readonly TrasportoPec $trasporto;

    public function __construct(?TrasportoPec $trasporto = null)
    {
        $this->trasporto = $trasporto ?? new TrasportoPecSmtp();
    }

    /** Manda la prova: `null` se il gestore l'ha accettata, il testo del server se no. */
    public function manda(ConfigurazionePec $casella, string $destinatario): ?string
    {
        $messaggio = (new Email())
            ->from(new Address($casella->indirizzo))
            ->to(new Address($destinatario))
            ->subject(self::OGGETTO)
            ->text(self::TESTO);

        $errore = null;
        try {
            $this->trasporto->manda($casella, $messaggio);
        } catch (\Throwable $e) {
            $errore = mb_substr($e->getMessage() !== '' ? $e->getMessage() : $e::class, 0, 1000);
            Log::error('Prova della casella PEC non andata: ' . $errore, ['server' => $casella->server, 'porta' => $casella->porta, 'cifratura' => $casella->cifratura]);
        }

        MailLog::create([
            'recipient' => $destinatario,
            'subject' => self::OGGETTO,
            'mailer' => self::NELLO_STORICO,
            'status' => $errore === null ? 'sent' : 'failed',
            'error_message' => $errore,
            'sent_at' => now(),
        ]);

        return $errore;
    }
}
