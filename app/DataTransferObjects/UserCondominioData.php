<?php

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