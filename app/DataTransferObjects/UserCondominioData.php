<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\DataTransferObjects;

use App\Models\Anagrafica;
use Illuminate\Support\Collection;

class UserCondominioData
{
    public function __construct(
        public readonly Anagrafica $anagrafica,
        public readonly Collection $condominioIds
    ) {
    }

    // Optional helper method if you need array representation
    public function toArray(): array
    {
        return [
            'anagrafica' => $this->anagrafica,
            'condominio_ids' => $this->condominioIds,
        ];
    }
}