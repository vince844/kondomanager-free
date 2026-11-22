<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

/**
 * L'errore di un invio per PEC come lo legge chi lavora. Il registro degli invii tiene l'errore intero del server; la pagina mostra una frase semplice:
 *
 * - **la connessione non parte** (porta chiusa, server che non risponde, nome che non si trova): dove gira il programma la porta 465 in uscita può essere
 *   bloccata, e lo si dice con il server e la porta della casella;
 * - **l'utente o la password** rifiutati dal gestore;
 * - **il messaggio rifiutato** per un altro motivo, con il codice quando il server lo dà.
 */
final class ErroreDellaPec
{
    /** In testa all'errore di una PEC che il programma non è riuscito a preparare (il PDF, il messaggio): non è un errore del gestore. */
    public const PREPARAZIONE = 'Preparazione del messaggio: ';

    /** L'indirizzo PEC della persona scritto male: il messaggio non si compone. */
    public const INDIRIZZO_SCRITTO_MALE = 'L\'indirizzo PEC della persona non è scritto bene: correggilo nella sua scheda, poi mandala di nuovo.';

    /** Gli errori che dicono che il collegamento non è partito: dopo il primo, gli altri invii dello stesso gesto non si provano. */
    private const DI_CONNESSIONE = ['connection could not be established', 'unable to connect', 'connection timed out', 'timed out', 'connection refused',
        'getaddrinfo', 'name or service not known', 'no route to host', 'network is unreachable', 'operation timed out'];

    private const DI_ACCESSO = ['failed to authenticate', 'authentication failed', 'invalid credentials', '535'];

    public static function diConnessione(?string $errore): bool
    {
        $e = mb_strtolower((string) $errore);
        foreach (self::DI_CONNESSIONE as $pezzo) {
            if (str_contains($e, $pezzo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Il motivo di una prova non andata (decisione 572, proposta 7), uno dei sei della scena 3 della bozza o «altro»: le parole le dà
     * {@see MotivoDellaProva}. Prima il nome che non si trova e la connessione sicura, che arrivano dentro un errore di connessione; poi il tempo, il
     * rifiuto della connessione, l'utente o la password, il mittente.
     */
    public static function motivo(?string $errore): string
    {
        $e = mb_strtolower((string) $errore);
        $contiene = static fn (array $pezzi): bool => array_filter($pezzi, static fn (string $p) => str_contains($e, $p)) !== [];

        return match (true) {
            $contiene(['getaddrinfo', 'no such host', 'name or service not known', 'nodename nor servname', 'php_network_getaddresses',
                'temporary failure in name resolution']) => MotivoDellaProva::NOME,
            $contiene(['certificate', 'ssl operation failed', 'ssl routines', 'wrong version number', 'tls handshake', 'crypto enabling']) => MotivoDellaProva::CERTIFICATO,
            $contiene(['timed out', 'timeout']) => MotivoDellaProva::TEMPO,
            self::diConnessione($errore) => MotivoDellaProva::RIFIUTATA,
            $contiene(['failed to authenticate', 'authentication failed', 'invalid credentials', 'username and password not accepted'])
                || preg_match('/\b535\b/', $e) === 1 => MotivoDellaProva::ACCESSO,
            $contiene(['sender address rejected', 'sender rejected', 'not owned by user']) || preg_match('/\b553\b/', $e) === 1 => MotivoDellaProva::MITTENTE,
            default => MotivoDellaProva::ALTRO,
        };
    }

    /** La frase per la pagina, di un invio: quante PEC sono partite lo dice chi la usa. */
    public static function frase(?string $errore, ?ConfigurazionePec $casella): string
    {
        $e = mb_strtolower((string) $errore);
        $dove = $casella === null ? 'del gestore' : $casella->server . ', porta ' . $casella->porta;
        if (str_contains($e, 'does not comply with addr-spec')) {
            return self::INDIRIZZO_SCRITTO_MALE;
        }
        if (str_starts_with((string) $errore, self::PREPARAZIONE)) {
            return 'Il programma non è riuscito a preparare la PEC: mandala di nuovo; se succede ancora, chiedi a chi gestisce il programma.';
        }
        if (self::diConnessione($errore)) {
            return 'Il programma non riesce a collegarsi al server della PEC (' . $dove . '). Dove gira il programma la porta ' . ($casella?->porta ?? 465)
                . ' in uscita può essere chiusa: chiedi a chi gestisce il server di aprirla, poi riprova.';
        }
        foreach (self::DI_ACCESSO as $pezzo) {
            if (str_contains($e, $pezzo)) {
                return 'Il server della PEC (' . $dove . ') ha rifiutato l\'utente o la password della casella dello studio: controllali nelle impostazioni della posta.';
            }
        }
        $codice = preg_match('/\b([45]\d\d)\b/', (string) $errore, $m) === 1 ? ' (codice ' . $m[1] . ')' : '';

        return 'Il server della PEC non ha accettato il messaggio' . $codice . '.';
    }
}
