<?php

namespace App\Exceptions\FatturaElettronica;

/**
 * Il file passato a FatturaPaParser non è una FatturaPA leggibile.
 *
 * Non è una violazione di regola di dominio (DomainException): è un file
 * malformato o una busta che non si riesce ad aprire. HTTP: 422.
 *
 * ## Il messaggio va a schermo: il dettaglio tecnico viaggia a parte
 *
 * ⚠️ `getMessage()` finisce **tale e quale** sotto il riquadro «Allega documento»
 * (`ImportaFatturaXmlController` → `data.errore` → `useImportaFatturaXml`), quindi lo
 * legge un amministratore di condominio. Fino al 03/09/2026 ci finiva anche il testo
 * grezzo di libxml — «Specification mandates value for attribute non» — corretto nel
 * merito e inservibile per chi non scrive software: una frase su cui non si può agire.
 *
 * Il dettaglio però serve, quando un utente scrive che «non riesce a caricare la
 * fattura»: viaggia in `$dettaglioTecnico` e lo scrive nel log il controller, che nel
 * framework ci vive già. Il parser resta senza facade, ed è ciò che lo rende collaudabile
 * in `tests/Unit` senza avviare Laravel.
 */
class FatturaPaParseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $dettaglioTecnico = null,
        public readonly ?int $riga = null,
    ) {
        parent::__construct($message);
    }
}
