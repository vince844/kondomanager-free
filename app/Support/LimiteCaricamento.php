<?php

namespace App\Support;

/**
 * Quanto pesa davvero il file più grande che si può caricare.
 *
 * ## Perché esiste
 *
 * Segnalazione dal forum del 18/08/2026: un file da 4.376 KB veniva rifiutato con un errore generico
 * su una schermata che dichiarava «Max 10MB». I numeri in gioco erano **tre**, e a vincere era
 * sempre quello che non dichiaravamo:
 *
 * - il testo della schermata: 10 MB, scritto a mano;
 * - la nostra regola di validazione: `max:20480`, cioè 20 MB;
 * - `upload_max_filesize` e `post_max_size` del server: sul suo, circa 2 MB.
 *
 * Il terzo non è una nostra scelta e cambia da installazione a installazione — è la ragione per cui
 * scrivere un numero fisso in una schermata è sbagliato in partenza. Quando il file lo supera **non
 * arriva mai a Laravel**: lo scarta PHP, e resta solo la regola `uploaded`, che produce il messaggio
 * generico.
 *
 * ## Cosa fa
 *
 * Un numero solo, calcolato dove serve: il **minimo** fra i due limiti di PHP e il tetto che
 * vogliamo comunque tenere. Da qui lo prendono la schermata (per scriverlo), la validazione (per non
 * promettere di più) e il messaggio d'errore (per dirlo quando il file è troppo grande).
 */
final class LimiteCaricamento
{
    /** Il tetto che il programma si dà comunque, indipendente dal server. */
    private const TETTO_MB = 20.0;

    /**
     * Converte una sigla di `php.ini` in byte. `2M` non è «2», ed è la trappola di partenza.
     * Un valore `-1` significa «nessun limite»: si tratta come il massimo rappresentabile.
     */
    public static function interpreta(string $valore): int
    {
        $valore = trim($valore);

        // ⚠️ In `php.ini` **`0` significa «nessun limite»**, non «zero byte»: è la configurazione
        // documentata da PHP per toglierlo, e la sceglie chi vuole caricamenti grandi. La prima
        // stesura la leggeva alla lettera e trasformava la correzione in un blocco totale — «max
        // 0 MB», ogni file respinto — proprio su chi il limite lo aveva tolto apposta.
        if ($valore === '' || $valore === '-1' || (int) $valore === 0) {
            return PHP_INT_MAX;
        }

        // Le sigle di `php.ini` possono essere minuscole (`2m`) e circondate da spazi.
        $unita = strtoupper(substr($valore, -1));
        $numero = (int) $valore;

        return match ($unita) {
            'G' => $numero * 1024 * 1024 * 1024,
            'M' => $numero * 1024 * 1024,
            'K' => $numero * 1024,
            default => $numero,
        };
    }

    /** Il valore di una direttiva di `php.ini`, in byte. */
    public static function daIni(string $direttiva): int
    {
        return self::interpreta((string) ini_get($direttiva));
    }

    /**
     * Il limite imposto dal **server**, senza il nostro tetto.
     *
     * ⚠️ Serve al messaggio della regola `uploaded`, che scatta quando è PHP ad aver scartato il
     * file: in quel momento il nostro tetto di 20 MB non è mai entrato in gioco, e dichiararlo
     * porterebbe il limite dei documenti su schermate che ne hanno altri — l'importatore ne dichiara
     * 25. Reperto della revisione della beta.58: sostituiva una bugia generica con una informata.
     */
    public static function byteServer(): int
    {
        return min(self::daIni('upload_max_filesize'), self::daIni('post_max_size'));
    }

    /** Il limite del server, scritto per l'utente. */
    public static function etichettaServer(): string
    {
        return self::inMegabyte(self::byteServer());
    }

    /** Il limite effettivo in megabyte, arrotondato per difetto a un decimale. */
    public static function megabyte(): float
    {
        $byte = min(
            self::daIni('upload_max_filesize'),
            self::daIni('post_max_size'),
            (int) (self::TETTO_MB * 1024 * 1024),
        );

        return floor($byte / 1048576 * 10) / 10;
    }

    /** Il valore da dare alla regola `max:` di Laravel, che ragiona in kilobyte. */
    public static function regolaMax(): int
    {
        return (int) floor(self::megabyte() * 1024);
    }

    /** Il limite **complessivo** della richiesta, che è un'altra cosa dal limite del singolo file. */
    public static function etichettaPost(): string
    {
        $mb = floor(self::daIni('post_max_size') / 1048576 * 10) / 10;

        return rtrim(rtrim(number_format($mb, 1, ',', '.'), '0'), ',').' MB';
    }

    /** Come si scrive all'utente: «10 MB», «1,9 MB». */
    public static function etichetta(): string
    {
        return self::inMegabyte((int) (self::megabyte() * 1048576));
    }

    /** Un numero di byte scritto per l'utente, senza decimali inutili. */
    private static function inMegabyte(int $byte): string
    {
        $mb = floor($byte / 1048576 * 10) / 10;

        return rtrim(rtrim(number_format($mb, 1, ',', '.'), '0'), ',').' MB';
    }
}
