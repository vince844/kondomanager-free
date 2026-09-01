<?php

namespace App\Exceptions\FatturaElettronica;

/**
 * Il file passato a FatturaPaParser non è una FatturaPA leggibile.
 *
 * Non è una violazione di regola di dominio (DomainException): è un file
 * malformato o una busta che non si riesce ad aprire. HTTP: 422.
 */
class FatturaPaParseException extends \RuntimeException {}
