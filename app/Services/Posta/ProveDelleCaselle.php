<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use App\Settings\MailSettings;
use App\Settings\PecSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Il programma ricorda l'ultima prova di ogni casella dello studio (decisione 572, proposta 5): quando, com'è andata, il motivo e il testo del server, verso
 * chi, e l'impronta della casella provata ({@see ImprontaDellaCasella}). La riga «Adesso» della pagina la legge da qui; la prova vale per la casella salvata
 * solo se l'impronta è la stessa.
 *
 * L'ultima prova e il giorno dell'impostazione non entrano nel registro delle modifiche: sono del programma, non scelte dello studio.
 */
final class ProveDelleCaselle
{
    /** Ricorda la prova della casella PEC. */
    public static function ricordaPec(bool $andata, ?string $errore, string $destinatario, string $impronta): void
    {
        self::senzaFermareLaProva(function () use ($andata, $errore, $destinatario, $impronta) {
            $s = app(PecSettings::class);
            $s->ultima_prova = self::prova($andata, $errore, $destinatario, $impronta);
            $s->save();
        });
    }

    /** Ricorda la prova della posta dello studio. */
    public static function ricordaPosta(bool $andata, ?string $errore, string $destinatario, string $impronta): void
    {
        self::senzaFermareLaProva(function () use ($andata, $errore, $destinatario, $impronta) {
            $s = app(MailSettings::class);
            $s->mail_ultima_prova = self::prova($andata, $errore, $destinatario, $impronta);
            $s->save();
        });
    }

    /** L'impronta della posta salvata: `null` finché manca il server (con il modo SMTP). */
    public static function improntaDellaPosta(MailSettings $s): ?string
    {
        if (($s->mail_driver ?? 'smtp') === 'sendmail') {
            return ImprontaDellaCasella::sendmail();
        }
        if (! filled($s->mail_host)) {
            return null;
        }

        return ImprontaDellaCasella::di('smtp', $s->mail_host, $s->mail_port, $s->mail_encryption, $s->mail_username, self::passwordDellaPosta($s->mail_password));
    }

    /** Come la prova della posta legge la password salvata: decifrata, o com'è se non si decifra (le installazioni di prima della cifratura). */
    private static function passwordDellaPosta(?string $salvata): ?string
    {
        if (! filled($salvata)) {
            return null;
        }
        try {
            return Crypt::decryptString((string) $salvata);
        } catch (DecryptException) {
            return (string) $salvata;
        }
    }

    /** Ricordare la prova non deve cambiare la risposta della prova: un errore nel salvarla finisce nel registro del programma. */
    private static function senzaFermareLaProva(\Closure $ricorda): void
    {
        try {
            $ricorda();
        } catch (\Throwable $e) {
            Log::warning('L\'ultima prova della casella non è stata ricordata: ' . $e->getMessage());
        }
    }

    /** «il 19/11 alle 10:14», nell'ora dello studio; con l'anno se non è quello di oggi. */
    public static function quando(?string $il): ?string
    {
        if (! filled($il)) {
            return null;
        }
        try {
            $giorno = Carbon::parse((string) $il)->setTimezone((string) config('app.user_timezone', 'Europe/Rome'));
        } catch (\Throwable) {
            return null;
        }
        $oggi = Carbon::now((string) config('app.user_timezone', 'Europe/Rome'));

        return 'il ' . $giorno->format($giorno->year === $oggi->year ? 'd/m' : 'd/m/Y') . ' alle ' . $giorno->format('H:i');
    }

    /** @return array{il: string, andata: bool, motivo: ?string, testo: ?string, destinatario: string, impronta: string} */
    private static function prova(bool $andata, ?string $errore, string $destinatario, string $impronta): array
    {
        return [
            'il' => now()->toIso8601String(),
            'andata' => $andata,
            'motivo' => $andata ? null : ErroreDellaPec::motivo($errore),
            'testo' => $andata ? null : $errore,
            'destinatario' => $destinatario,
            'impronta' => $impronta,
        ];
    }
}
