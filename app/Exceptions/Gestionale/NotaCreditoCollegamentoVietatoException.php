<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Una nota di credito del fornitore che non si può collegare alla fattura indicata (Coda 165, 1.11.0-beta.36;
 * decisione 26, punto 6). Il messaggio viene dal modello (`FatturaPassiva::motivoBloccoNotaCollegata`) e contiene già la
 * via: la stessa scala dello storno, perché con la nota registrata le rate di un piano che non ha incassato chiederebbero
 * ancora la fattura intera.
 *
 * La lancia `FatturaPassivaService::registraFattura` — la terza porta, attraversata anche dalle chiamate dirette che non
 * passano dalla richiesta — dentro la transazione: nota, righe e scritture tornano indietro insieme.
 */
class NotaCreditoCollegamentoVietatoException extends Exception
{
    public function report(): bool
    {
        return false;
    }
}
