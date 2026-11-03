<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Un file del ripristino che non si trova, non si legge o non si scrive: l'archivio, la cartella di estrazione, un
 * file estratto, il dump. Il messaggio arriva alla finestra dei backup e al log della pagina 503, e non porta
 * percorsi del server; il percorso, e la causa che ha dato il sistema, vanno nel log del server con il contesto
 * dell'eccezione (`context()`, che il gestore delle eccezioni aggiunge alla riga del log).
 */
class RestoreFileException extends RuntimeException
{
    public function __construct(string $messaggio, public readonly string $percorso, public readonly ?string $causa = null)
    {
        parent::__construct($messaggio);
    }

    /**
     * @return array{percorso: string, causa?: string}
     */
    public function context(): array
    {
        return array_filter(['percorso' => $this->percorso, 'causa' => $this->causa], fn ($valore) => $valore !== null);
    }

    /**
     * Esegue un'operazione sui file e ne raccoglie l'avviso del sistema, invece di lasciarlo diventare un'eccezione con
     * il percorso nel messaggio: l'avviso diventa la causa, che va nel log del server.
     *
     * @template T
     *
     * @param  callable(): T  $operazione
     * @return array{0: T, 1: string|null} il risultato, e l'avviso se c'è stato
     */
    public static function raccogliAvviso(callable $operazione): array
    {
        $avviso = null;

        set_error_handler(function (int $livello, string $messaggio) use (&$avviso): bool {
            $avviso = $messaggio;

            return true;
        });

        try {
            $risultato = $operazione();
        } finally {
            restore_error_handler();
        }

        return [$risultato, $avviso];
    }
}
