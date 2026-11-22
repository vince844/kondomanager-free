<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

/**
 * Le parole di una prova non andata (decisione 572, proposta 7, scena 3 della bozza): il motivo in parole semplici, che cosa fare, la forma breve per la
 * riga «Adesso» e il campo da correggere, per la casella PEC e per la posta dello studio. Il motivo lo sceglie {@see ErroreDellaPec::motivo()} dal testo del
 * server, che la pagina mostra sotto, piccolo, per chi assiste lo studio.
 *
 * Le parole della PEC sono quelle della bozza; quelle della posta le stesse, con il server della casella al posto del gestore PEC.
 */
final class MotivoDellaProva
{
    public const ACCESSO = 'accesso';
    public const RIFIUTATA = 'rifiutata';
    public const TEMPO = 'tempo';
    public const NOME = 'nome';
    public const MITTENTE = 'mittente';
    public const CERTIFICATO = 'certificato';
    public const ALTRO = 'altro';

    /** Il campo da correggere per motivo: la pagina mette lì il cursore. */
    private const AZIONI = [
        self::ACCESSO => ['campo' => 'password', 'etichetta' => 'Riscrivi la password'],
        self::RIFIUTATA => ['campo' => 'porta', 'etichetta' => 'Cambia la porta'],
        self::TEMPO => ['campo' => 'server', 'etichetta' => 'Correggi il server'],
        self::NOME => ['campo' => 'server', 'etichetta' => 'Correggi il server'],
        self::MITTENTE => ['campo' => 'utente', 'etichetta' => 'Correggi l\'utente'],
        self::CERTIFICATO => ['campo' => 'cifratura', 'etichetta' => 'Cambia la cifratura'],
        self::ALTRO => null,
    ];

    /** [titolo, che cosa fare, forma breve] per la casella PEC: le parole della scena 3. */
    private const PEC = [
        self::ACCESSO => ['Il gestore non ha accettato l\'utente o la password.',
            'Riscrivi la password della casella PEC e riprova. Guarda anche l\'utente: di solito è l\'indirizzo PEC intero.',
            'il gestore non ha accettato la password'],
        self::RIFIUTATA => ['Il server non accetta la connessione su questa porta.',
            'Per la PEC quasi sempre 465 con SSL. Se è già così, chiedi a chi gestisce il server se le connessioni in uscita sulla 465 sono aperte.',
            'il server non accetta la connessione su questa porta'],
        self::TEMPO => ['Il server non ha risposto in tempo.',
            'Controlla il nome del server. Se è giusto, la porta è chiusa in uscita dal server del programma.',
            'il server non ha risposto in tempo'],
        self::NOME => ['Il server con questo nome non esiste.',
            'Controlla di averlo scritto bene: è nelle istruzioni del gestore, alla voce «posta in uscita».',
            'il server con questo nome non esiste'],
        self::MITTENTE => ['Il gestore accetta solo la posta che parte dall\'indirizzo della casella.',
            'L\'indirizzo PEC e l\'utente devono essere la stessa casella.',
            'il gestore accetta solo la posta che parte dall\'indirizzo della casella'],
        self::CERTIFICATO => ['La connessione sicura non si è aperta.',
            'Controlla la cifratura: SSL con la 465, TLS con la 587.',
            'la connessione sicura non si è aperta'],
        self::ALTRO => ['Il server ha rifiutato la prova.',
            'Il testo del server è sotto il motivo: mandalo a chi ti assiste. Si possono fare sei prove al minuto.',
            'il server ha rifiutato la prova'],
    ];

    /** Le stesse per la posta dello studio. */
    private const POSTA = [
        self::ACCESSO => ['Il server non ha accettato l\'utente o la password.',
            'Riscrivi la password della casella e riprova. Guarda anche l\'utente: di solito è l\'indirizzo intero.',
            'il server non ha accettato la password'],
        self::RIFIUTATA => ['Il server non accetta la connessione su questa porta.',
            'Di solito 587 con TLS, oppure 465 con SSL. Se è già così, chiedi a chi gestisce il server se le connessioni in uscita su quella porta sono aperte.',
            'il server non accetta la connessione su questa porta'],
        self::TEMPO => ['Il server non ha risposto in tempo.',
            'Controlla il nome del server. Se è giusto, la porta è chiusa in uscita dal server del programma.',
            'il server non ha risposto in tempo'],
        self::NOME => ['Il server con questo nome non esiste.',
            'Controlla di averlo scritto bene: è nelle istruzioni della tua casella, alla voce «posta in uscita» o «SMTP».',
            'il server con questo nome non esiste'],
        self::MITTENTE => ['Il server accetta solo la posta che parte dall\'indirizzo della casella.',
            'L\'indirizzo del mittente deve essere quello della casella, o uno che il servizio di invio ha verificato.',
            'il server accetta solo la posta che parte dall\'indirizzo della casella'],
        self::CERTIFICATO => ['La connessione sicura non si è aperta.',
            'Controlla la cifratura: TLS con la 587, SSL con la 465.',
            'la connessione sicura non si è aperta'],
        self::ALTRO => ['Il server ha rifiutato la prova.',
            'Il testo del server è sotto il motivo: mandalo a chi ti assiste. Si possono fare sei prove al minuto.',
            'il server ha rifiutato la prova'],
    ];

    /**
     * Le parole del motivo per la casella (`pec` o `posta`).
     *
     * @return array{chiave: string, titolo: string, fai: string, breve: string, azione: array{campo: string, etichetta: string}|null}
     */
    public static function per(string $motivo, string $casella): array
    {
        $parole = $casella === 'pec' ? self::PEC : self::POSTA;
        $motivo = array_key_exists($motivo, $parole) ? $motivo : self::ALTRO;
        [$titolo, $fai, $breve] = $parole[$motivo];
        $azione = self::AZIONI[$motivo];
        if ($azione !== null && $azione['campo'] === 'utente' && $casella === 'posta') {
            $azione = ['campo' => 'mittente', 'etichetta' => 'Correggi il mittente'];
        }

        return ['chiave' => $motivo, 'titolo' => $titolo, 'fai' => $fai, 'breve' => $breve, 'azione' => $azione];
    }
}
