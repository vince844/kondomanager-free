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

    /**
     * Il limite effettivo in megabyte, arrotondato per difetto a un decimale.
     *
     * ⚠️ **`$tettoMb` è il tetto che quella porta si dà**, e non è un dettaglio di comodo: fino alla
     * beta.59 il tetto era uno solo, 20 MB, giusto per i documenti e sbagliato per tutte le altre
     * porte. L'allegato di una fattura si dà 10 MB, l'importatore 25, la firma di stampa 2 — e
     * applicare a tutte il tetto dei documenti avrebbe **abbassato del 20% l'importatore**, cioè
     * rotto la voce di punta della 1.10 per correggere un difetto di forma.
     *
     * Quello che questa classe garantisce non è «tutti allo stesso numero»: è che **nessuna porta
     * prometta più di quanto il server accetti davvero**. Il tetto della porta entra nel minimo, non
     * lo sostituisce.
     */
    public static function megabyte(?float $tettoMb = null): float
    {
        $byte = min(
            self::daIni('upload_max_filesize'),
            self::daIni('post_max_size'),
            (int) (($tettoMb ?? self::TETTO_MB) * 1024 * 1024),
        );

        return floor($byte / 1048576 * 10) / 10;
    }

    /**
     * Il valore da dare alla regola `max:` di Laravel, che ragiona in kilobyte.
     *
     * Si passa il tetto della porta quando ne ha uno diverso da quello dei documenti — vedi
     * `megabyte()`. Senza argomento vale `TETTO_MB`, che è il comportamento di prima.
     */
    public static function regolaMax(?float $tettoMb = null): int
    {
        return (int) floor(self::megabyte($tettoMb) * 1024);
    }

    /** Il limite **complessivo** della richiesta, che è un'altra cosa dal limite del singolo file. */
    public static function etichettaPost(): string
    {
        return self::scriviMegabyte(floor(self::daIni('post_max_size') / 1048576 * 10) / 10);
    }

    /**
     * Come si scrive all'utente: «10 MB», «1,9 MB».
     *
     * Prende lo stesso `$tettoMb` di `regolaMax()`, e va passato **lo stesso valore**: è la coppia
     * che la beta.58 ha già sbagliato una volta, con la regola che accettava 20 MB e la schermata
     * che ne scriveva 10.
     *
     * ⚠️ **Scrive `megabyte()` così com'è, senza rifare il giro per i byte.** La prima stesura faceva
     * `inMegabyte((int) (megabyte() * 1048576))`, cioè arrotondava una seconda volta un numero già
     * arrotondato per difetto: su **209 valori su 299** l'etichetta usciva diversa dalla regola, e in
     * uno scenario misurato l'utente vedeva **tre numeri per lo stesso limite** — 1,8 MB sulla
     * schermata, 1,899 nella regola, 1,9 nel messaggio d'errore. Trovato dalla revisione della .60.
     */
    public static function etichetta(?float $tettoMb = null): string
    {
        return self::scriviMegabyte(self::megabyte($tettoMb));
    }

    /** Un numero di byte scritto per l'utente, senza decimali inutili. */
    private static function inMegabyte(int $byte): string
    {
        return self::scriviMegabyte(floor($byte / 1048576 * 10) / 10);
    }

    /**
     * Un numero di kilobyte — come li scrive la regola `max:` — riscritto per una persona.
     *
     * Serve al messaggio d'errore della **nostra** regola: senza, Laravel dice «non può essere più
     * grande di 20480 kilobytes», che è il numero giusto detto nel modo peggiore, e per giunta in
     * un'unità diversa da quella che la schermata accanto ha appena scritto.
     */
    public static function daKilobyte(int $kilobyte): string
    {
        return self::scriviMegabyte(floor($kilobyte / 1024 * 10) / 10);
    }

    /**
     * Il modo unico di scrivere un numero di megabyte all'utente.
     *
     * Sta in un metodo suo perché lo usano tre strade — l'etichetta della porta, quella del server e
     * quella della richiesta intera — e devono scrivere allo stesso modo.
     */
    private static function scriviMegabyte(float $mb): string
    {
        return rtrim(rtrim(number_format($mb, 1, ',', '.'), '0'), ',').' MB';
    }
}
