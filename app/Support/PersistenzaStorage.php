<?php

namespace App\Support;

/**
 * I documenti caricati sopravvivono al prossimo rebuild?
 *
 * ## Perché esiste
 *
 * Chiesto da Vincenzo il 18/08/2026: su Coolify il volume per `storage/app` si dichiara a mano nel
 * pannello, ed è un passaggio che si può dimenticare. Se manca, i documenti caricati, i backup e i
 * file pubblici vivono nel livello scrivibile del contenitore e **spariscono alla sua ricreazione** —
 * senza nessun errore, senza nessun avviso, e ci si accorge quando si va a cercare un documento.
 *
 * `VOLUME` nel Dockerfile **non risolve**: dichiara un punto di innesto e, in assenza di una mappa
 * esplicita, Docker crea un volume **anonimo** diverso a ogni contenitore. I file precedenti restano
 * in un volume che nessuno riaggancia. Il volume va dichiarato dove il contenitore viene eseguito.
 *
 * ## Come si riconosce
 *
 * Un volume montato è un filesystem diverso da quello del contenitore: `stat()` ne restituisce un
 * **numero di dispositivo** diverso da quello della radice del progetto. Se coincidono, la cartella
 * sta nel livello del contenitore. È un confronto, non un'euristica sui nomi.
 */
final class PersistenzaStorage
{
    /** La cartella sta su un filesystem diverso dalla radice del **contenitore**? */
    public static function suVolumeSeparato(string $percorso): bool
    {
        if (! is_dir($percorso)) {
            return false;
        }

        $dellaCartella = @stat($percorso);
        $confronto = self::dispositivoDiConfronto();

        if ($dellaCartella === false || $confronto === null) {
            return false;
        }

        return $dellaCartella['dev'] !== $confronto;
    }

    /**
     * Il dispositivo con cui confrontare: **la radice del filesystem**, non la cartella del progetto.
     *
     * ⚠️ Reperto della revisione della beta.58. La prima stesura confrontava con `base_path()`, e
     * chi monta **l'intera cartella dell'applicazione** — configurazione comune su Coolify e in
     * qualunque compose con un bind mount — si sentiva dire «non è persistente, spariscono 412 file
     * per 180 MB»: falso, i suoi file sono al sicuro. E nel comando post-deploy l'uscita 1 avrebbe
     * fatto risultare fallito un deploy perfettamente riuscito.
     *
     * Con la radice il confronto risponde alla domanda giusta — *questi file stanno su un volume?* —
     * qualunque sia la cartella montata: la sua, una superiore, o nessuna.
     */
    private static function dispositivoDiConfronto(): ?int
    {
        $radice = @stat('/');

        return $radice === false ? null : $radice['dev'];
    }

    /**
     * Il verdetto completo: se è persistente, dove guarda, e **quanto c'è da perdere**.
     * I numeri servono: un avviso che dice «attenzione» senza dire quanto non lo legge nessuno.
     *
     * @return array{persistente: bool, percorso: string, cartelle: array<string, array{file: int, byte: int}>}
     */
    public static function verdetto(): array
    {
        $radice = storage_path('app');

        $cartelle = [];
        foreach (['private', 'backups', 'public'] as $nome) {
            $cartelle[$nome] = self::misura($radice.DIRECTORY_SEPARATOR.$nome);
        }

        return [
            'persistente' => self::suVolumeSeparato($radice),
            'percorso' => $radice,
            'cartelle' => $cartelle,
        ];
    }

    /** @return array{file: int, byte: int} */
    private static function misura(string $percorso): array
    {
        if (! is_dir($percorso)) {
            return ['file' => 0, 'byte' => 0];
        }

        $file = 0;
        $byte = 0;

        $iteratore = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($percorso, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iteratore as $elemento) {
            if ($elemento->isFile()) {
                $file++;
                $byte += $elemento->getSize();
            }
        }

        return ['file' => $file, 'byte' => $byte];
    }
}
