<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

/**
 * L'impronta di una casella di posta: il modo di mandare, il server, la porta, la cifratura, l'utente e la password (decisione 572, proposta 5). La prova
 * la scrive accanto al suo esito; la pagina la confronta con quella della casella salvata. Se sono uguali la casella è «Provata» (o «Prova non andata»);
 * se una delle sei cose è cambiata, è di nuovo «Da provare». Così una prova fatta con i valori scritti e poi salvati vale, e una prova di valori mai salvati
 * non dice niente della casella salvata.
 *
 * È un HMAC con la chiave dell'applicazione: dall'impronta non si torna alla password, e due installazioni con la stessa password hanno impronte diverse.
 * Non esce mai verso la pagina. L'indirizzo del mittente e il nome non contano: la prova li usa, ma cambiarli non rende la casella da riprovare.
 */
final class ImprontaDellaCasella
{
    public static function di(string $modo, ?string $server, int|string|null $porta, ?string $cifratura, ?string $utente, ?string $password): string
    {
        $parti = [
            strtolower(trim($modo)),
            strtolower(trim((string) $server)),
            (int) $porta,
            self::cifratura($cifratura),
            trim((string) $utente),
            (string) $password,
        ];

        return hash_hmac('sha256', json_encode($parti, JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }

    /** La posta del server (sendmail) non ha server né password: la sua impronta è il modo, da solo. */
    public static function sendmail(): string
    {
        return self::di('sendmail', null, null, null, null, null);
    }

    /** «Nessuna» scritta in qualunque modo (la pagina della posta manda la stringa "null") vale vuoto. */
    public static function cifratura(?string $cifratura): string
    {
        $c = strtolower(trim((string) $cifratura));

        return in_array($c, ['', 'null', 'none'], true) ? '' : $c;
    }
}
