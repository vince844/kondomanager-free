<?php

namespace App\Services\Gestionale\Duplicati;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Riconosce se una `UniqueConstraintViolationException` viene dal vincolo unico su
 * `fatture_passive` (D1/D2, 1.11.0-beta.13), per distinguerla da qualunque altro indice
 * unico della stessa tabella (o di altre) prima di mostrare all'utente il messaggio di
 * dominio invece dell'errore SQL grezzo.
 *
 * ⚠️ **Estratta dal controller apposta per essere testabile senza database reale.**
 * `UniqueConstraintViolationException::$index` porta il nome dell'indice **solo su
 * MySQL**; su SQLite (la suite Pest, `tests/TestCase.php` lo impone) è sempre `null` e la
 * stessa informazione arriva in `$columns`. I due rami sono quindi **mutuamente esclusivi
 * per driver**: prima di questa estrazione il ramo che decide in produzione (quello sul
 * nome dell'indice) aveva copertura zero, perché non esiste un modo di costruire quello
 * stato dentro SQLite — solo di costruire l'eccezione a mano e passargliela, che è
 * esattamente quello che il test di questa classe fa. Trovato dalla revisione avversariale
 * della beta.13.
 *
 * ⚠️ **Accetta anche il vecchio nome dell'indice, `unique_ft`.** Non per compatibilità
 * astratta: nella finestra fra il deploy del codice e l'esecuzione della migrazione su
 * Coolify (vedi `docs/flusso_di_lavoro_rilascio.md` sul comando post-deploy), il database
 * può avere ancora il vincolo pre-D1. Riconoscere solo il nome nuovo avrebbe fatto
 * ricadere quella finestra nel `catch (\Exception)` generico — l'errore SQL grezzo a video,
 * lo stesso difetto che D2 esiste per chiudere — proprio nel momento in cui è più probabile
 * incontrarla.
 */
final class CollisioneUnicaFattura
{
    public const INDICI = ['unique_ft_condominio', 'unique_ft'];

    public static function rilevata(UniqueConstraintViolationException $e): bool
    {
        if ($e->index !== null && in_array($e->index, self::INDICI, true)) {
            return true;
        }

        return in_array('numero_documento', $e->columns, true)
            && in_array('data_documento', $e->columns, true);
    }
}
