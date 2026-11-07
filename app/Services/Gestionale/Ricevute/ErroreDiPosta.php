<?php

namespace App\Services\Gestionale\Ricevute;

use App\Enums\Permission;
use App\Notifications\Gestionale\RicevutaIncassoNotification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * L'errore del server di posta come lo legge chi lavora (decisione 204). L'errore che il server dà può dire l'utente della posta o l'indirizzo
 * del server (un errore di autenticazione, di connessione): lo vede intero solo chi gestisce le impostazioni generali, come il registro delle
 * email. Tutti gli altri leggono una frase semplice, con il codice quando il server lo dà. Le frasi che scrive il programma stesso (senza
 * server di posta, incasso annullato prima dell'invio, il debito di una lettera cambiato prima dell'invio) si leggono come sono: ogni frase
 * nuova del programma che può finire nella colonna dell'errore va nell'elenco qui sotto, altrimenti si leggerebbe come un rifiuto del server.
 *
 * Il registro degli invii tiene l'errore intero: la frase si fa quando lo si mostra.
 */
class ErroreDiPosta
{
    public const FRASE = 'Il server di posta non ha accettato il messaggio';

    /** La frase di un errore che non viene dal server di posta (per esempio mentre il programma fa il PDF): non è colpa del server, e non ha un codice. */
    public const FRASE_DEL_PROGRAMMA = 'Il messaggio non è partito per un errore del programma. Riprova; se si ripete, avvisa chi gestisce l\'installazione.';

    /** Con questo inizia l'errore che il registro tiene quando a sbagliare non è il trasporto di posta: la frase si sceglie leggendolo. */
    private const PREFISSO_DEL_PROGRAMMA = 'Errore del programma: ';

    /** @return array<int, string> */
    private static function delProgramma(): array
    {
        return [
            EmissioneRicevute::SENZA_SERVER_POSTA,
            EmissioneRicevute::NON_PARTITO_SENZA_SERVER,
            RicevutaIncassoNotification::ANNULLATO_PRIMA_DELL_INVIO,
            // Le lettere: il debito cambiato fra l'accodamento e la partenza, e il server di posta che non c'è.
            \App\Notifications\Gestionale\LetteraNotification::CAMBIATO_PRIMA_DELL_INVIO,
            \App\Services\Gestionale\Lettere\EmissioneLettere::SENZA_SERVER_POSTA,
            // L'avviso di compensazione il cui incasso è stato annullato fra l'accodamento e la partenza.
            \App\Notifications\Gestionale\AvvisoCompensazioneNotification::ANNULLATO_PRIMA_DELL_INVIO,
        ];
    }

    /** Una frase scritta dal programma: non viene dal server di posta, e non ha niente da nascondere. */
    public static function delProgrammaStesso(?string $errore): bool
    {
        return $errore !== null && in_array(trim($errore), self::delProgramma(), true);
    }

    /**
     * L'errore come lo scrive il registro: quello del trasporto di posta com'è (l'errore intero lo legge chi gestisce le impostazioni); quello
     * che al trasporto non appartiene (un errore mentre il programma fa il PDF, per esempio) con l'inizio che lo dice, così nessuno lo legge come
     * un rifiuto del server.
     */
    public static function daEccezione(\Throwable $e): string
    {
        $testo = mb_substr($e->getMessage() !== '' ? $e->getMessage() : $e::class, 0, 1000);

        return $e instanceof TransportExceptionInterface ? $testo : mb_substr(self::PREFISSO_DEL_PROGRAMMA . $testo, 0, 1000);
    }

    /** Un errore che non viene dal server di posta: lo dice l'inizio che gli ha messo {@see daEccezione()}. */
    public static function nonDelTrasporto(?string $errore): bool
    {
        return $errore !== null && str_starts_with(trim($errore), self::PREFISSO_DEL_PROGRAMMA);
    }

    /** Il codice che il server ha dato (per esempio 535), se l'errore lo dice: non il codice che il programma si aspettava («but got empty code»). */
    public static function codice(string $errore): ?string
    {
        return preg_match('/but got code "(\d{3})"/', $errore, $trovato) === 1 ? $trovato[1] : null;
    }

    /** La frase che leggono tutti: l'errore del programma com'è, quello del server come frase semplice con il codice. */
    public static function frase(?string $errore): ?string
    {
        if ($errore === null || trim($errore) === '') {
            return null;
        }
        if (self::delProgrammaStesso($errore)) {
            return trim($errore);
        }
        if (self::nonDelTrasporto($errore)) {
            return self::FRASE_DEL_PROGRAMMA;
        }
        $codice = self::codice($errore);

        return self::FRASE . ($codice !== null ? " (codice {$codice})" : '') . '.';
    }

    /** Chi vede l'errore intero: chi gestisce le impostazioni generali, la stessa regola del registro delle email. */
    public static function chiVedeTutto(): bool
    {
        return auth()->user()?->can(Permission::MANAGE_GENERAL_SETTINGS->value) === true;
    }

    /** L'errore intero del server, solo per chi può leggerlo; null per gli altri e per le frasi del programma. */
    public static function completoPerChiGuarda(?string $errore): ?string
    {
        if ($errore === null || trim($errore) === '' || self::delProgrammaStesso($errore) || ! self::chiVedeTutto()) {
            return null;
        }

        return $errore;
    }
}
