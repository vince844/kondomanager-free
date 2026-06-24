<?php

namespace App\Exceptions\Gestionale;

use Exception;

class ScopertiNonAccettatiException extends Exception
{
    protected array $scoperti;

    public function __construct(array $scoperti)
    {
        $this->scoperti = $scoperti;
        parent::__construct("Ci sono quote scoperte che richiedono approvazione esplicita.");
    }

    public function getScoperti(): array
    {
        return $this->scoperti;
    }

    public function report(): bool
    {
        return false;
    }
}
