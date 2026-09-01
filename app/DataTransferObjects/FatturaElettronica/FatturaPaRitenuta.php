<?php

namespace App\DataTransferObjects\FatturaElettronica;

/**
 * Un blocco DatiRitenuta, a livello di documento (non di riga). Importo in
 * centesimi. Nessuna delle tre fixture ufficiali dell'Agenzia ne contiene
 * uno: verificato il 01/09/2026, va provato con una fixture nostra
 * validata contro l'XSD (docs/lettura_xml_fatture_passive.md).
 */
class FatturaPaRitenuta
{
    public function __construct(
        public readonly ?string $tipoRitenuta,
        public readonly int $importoCents,
        public readonly float $aliquota,
        public readonly ?string $causalePagamento,
    ) {
    }
}
