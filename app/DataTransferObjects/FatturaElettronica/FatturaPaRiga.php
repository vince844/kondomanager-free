<?php

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
