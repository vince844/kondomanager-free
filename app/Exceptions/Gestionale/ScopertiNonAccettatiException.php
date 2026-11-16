<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
