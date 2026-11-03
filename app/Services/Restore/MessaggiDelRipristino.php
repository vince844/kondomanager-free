<?php

namespace App\Services\Restore;

use Illuminate\Contracts\Container\BindingResolutionException;

/**
 * I messaggi del motore del ripristino che arrivano alla finestra dei backup (un archivio che non si apre, un
 * backup non compatibile, un ripristino o un backup già in corso), nella lingua dell'installazione. Le frasi
 * stanno in `impostazioni.php` di ogni lingua, sotto `restore_engine`, e non nel codice.
 *
 * `ArchiveReader` si usa anche fuori dall'applicazione (i suoi test girano senza il framework): lì il messaggio è
 * la chiave, e l'eccezione resta la stessa.
 */
final class MessaggiDelRipristino
{
    /**
     * @param  array<string, string|int>  $valori
     */
    public static function di(string $chiave, array $valori = []): string
    {
        $chiave = 'impostazioni.restore_engine.'.$chiave;

        try {
            return (string) __($chiave, $valori);
        } catch (BindingResolutionException) {
            return $chiave;
        }
    }

    /**
     * Il messaggio di un errore senza le cartelle del server in cui lavorano il ripristino e i backup: quella
     * dell'installazione, quella dei temporanei (`backup.tmp_path`) e quella degli archivi (il disco dei backup), che si
     * possono spostare fuori dalla prima. Al loro posto «…»: il resto del percorso resta, e dice all'assistenza quale
     * file. È il messaggio che lo stato di un ripristino fallito porta alla finestra dei backup e al log della pagina
     * 503, e che un backup fallito salva nel suo errore (la finestra dei backup lo ripassa da qui quando lo mostra, per
     * gli errori salvati prima che ci passassero); quello intero, con il percorso, va nel log del server.
     *
     * Una cartella si toglie solo dove apre un percorso: prima l'inizio del messaggio, uno spazio, una parentesi, le
     * virgolette, una virgola, «=», «:» o «;»; dopo una «/», la fine del percorso o il punto che chiude la frase (seguito
     * da uno spazio o dalla fine: «/app.old» resta intero). Le cartelle più lunghe prima, così una cartella dentro
     * un'altra si toglie intera. Nell'immagine Docker l'installazione è `/app`: senza i limiti sparirebbe anche dentro
     * `storage/app/` e dentro i dati di un errore del database.
     *
     * Serve per gli errori del sistema (un avviso di PHP su un file, la finalizzazione): quelli del ripristino stesso
     * hanno già un messaggio senza percorsi (`RestoreFileException`).
     */
    public static function senzaPercorsi(string $messaggio): string
    {
        // Una cartella vuota (o la radice «/») resta vuota, e non toglie niente.
        $cartelle = array_values(array_unique(array_filter(array_map(fn (string $cartella) => rtrim($cartella, '/'), [
            base_path(),
            (string) config('backup.tmp_path'),
            (string) config('filesystems.disks.'.config('backup.disk', 'backups').'.root'),
        ]), fn (string $cartella) => $cartella !== '')));

        if ($cartelle === []) {
            return $messaggio;
        }

        usort($cartelle, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $alternative = implode('|', array_map(fn (string $cartella) => preg_quote($cartella, '#'), $cartelle));

        $senza = preg_replace('#(^|[\s(\[{\'"`,=:;])(?:'.$alternative.')(?=/|$|[\s)\]}\'"`,;:]|\.(?:\s|$))#', '$1…', $messaggio);

        // Se l'espressione non si applica, ogni occorrenza delle cartelle sparisce: meglio un messaggio storpiato che un
        // percorso del server fuori dal log.
        return $senza ?? str_replace($cartelle, '…', $messaggio);
    }
}
