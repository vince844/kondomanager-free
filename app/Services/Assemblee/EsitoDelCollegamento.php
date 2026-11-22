<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Models\Assemblee\CollegamentoAllaDelibera;

/**
 * L'esito di un collegamento (R1 del progetto della tappa 2): la riga del collegamento, **la data che entra nella scheda** per la sua strada di oggi (il
 * giorno della riunione), o `null` quando la scheda non la cambia (il piano già emesso: R1 (c)), con la frase della **differenza** da mostrare («La delibera
 * è del 22/10/2026, il piano dice 20/10/2026») e la conseguenza dove la delibera muove chi paga.
 */
final class EsitoDelCollegamento
{
    public function __construct(
        public readonly CollegamentoAllaDelibera $collegamento,
        public readonly ?string $dataEntrata,
        public readonly ?string $differenza,
        public readonly ?string $conseguenza,
    ) {}

    /** @return array{data_entrata: ?string, differenza: ?string, conseguenza: ?string, delibera_id: int, ruolo: string} */
    public function perLaPagina(): array
    {
        return ['data_entrata' => $this->dataEntrata, 'differenza' => $this->differenza, 'conseguenza' => $this->conseguenza,
            'delibera_id' => (int) $this->collegamento->delibera_id, 'ruolo' => (string) $this->collegamento->ruolo?->value];
    }
}
