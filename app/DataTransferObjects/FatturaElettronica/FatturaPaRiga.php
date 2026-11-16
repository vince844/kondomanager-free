<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\DataTransferObjects\FatturaElettronica;

/**
 * Una riga di DettaglioLinee. Importi in centesimi (MoneyHelper::toCents,
 * convertiti in FatturaPaParser — mai a valle).
 */
class FatturaPaRiga
{
    public function __construct(
        public readonly string $descrizione,
        public readonly int $importoImponibileCents,
        public readonly float $aliquotaIva,
        public readonly ?string $natura,
    ) {
    }
}
