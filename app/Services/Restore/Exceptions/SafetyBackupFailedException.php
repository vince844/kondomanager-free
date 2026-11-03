<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Il backup di sicurezza non è riuscito, e il ripristino si ferma prima di toccare il database. Il messaggio arriva alla
 * finestra dei backup e dice che cosa fare. La causa va nel log del server: quella di un backup che non parte con
 * l'eccezione di prima (`getPrevious()`), quella di un backup che non finisce con la riga del log del backup stesso
 * (`BackupManager::fail`), che il contesto di questa (`context()`, il backup) permette di ritrovare.
 */
class SafetyBackupFailedException extends RuntimeException
{
    public function __construct(string $messaggio, public readonly ?string $backup = null, ?Throwable $precedente = null)
    {
        parent::__construct($messaggio, 0, $precedente);
    }

    /**
     * @return array{backup?: string}
     */
    public function context(): array
    {
        return $this->backup !== null ? ['backup' => $this->backup] : [];
    }
}
