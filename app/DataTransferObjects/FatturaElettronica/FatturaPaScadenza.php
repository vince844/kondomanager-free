<?php

namespace App\DataTransferObjects\FatturaElettronica;

/**
 * Una riga di DettaglioPagamento. Importo in centesimi.
 *
 * `data` è nullable perché DataScadenzaPagamento è `minOccurs="0"` nello
 * XSD ufficiale (verificato 01/09/2026): il tracciato ammette termini
 * espressi in giorni da una data di riferimento invece di una scadenza
 * puntuale. Quando manca, resta da compilare a mano — il parser non
 * inventa una data che il file non dichiara.
 */
class FatturaPaScadenza
{
    public function __construct(
        public readonly ?string $data,
        public readonly int $importoCents,
        public readonly ?string $modalitaPagamento,
        public readonly ?string $iban,
    ) {
    }
}
