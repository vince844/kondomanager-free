<?php

namespace App\DataTransferObjects\FatturaElettronica;

/**
 * Un blocco `DatiFattureCollegate`: il documento che questo documento dichiara di rettificare (Coda 165,
 * 1.11.0-beta.36). Su una nota di credito è la fattura che la nota corregge.
 *
 * `data` è nullable perché nello XSD `Data` è `minOccurs="0"`, come quasi tutto il blocco tranne `IdDocumento`. Senza la
 * data la fattura non si propone: molti fornitori ricominciano la numerazione a gennaio, e un numero da solo non basta.
 */
class FatturaPaFatturaCollegata
{
    public function __construct(
        public readonly string $numero,
        public readonly ?string $data,
    ) {
    }
}
