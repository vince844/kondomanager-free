<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * La nota di credito del fornitore si può registrare, collegare o modificare, ma la fattura che rettifica sta in un piano
 * che ha già incassato: le rate restano, e chi registra lo deve confermare (Coda 165, 1.11.0-beta.36; decisione 26,
 * punto 6; R3 e R5 della Fase 1-bis). Il messaggio è l'avviso (`FatturaPassiva::avvisoNotaCollegata` o
 * `avvisoModificaNota`), calcolato con le righe vere dentro la transazione, che torna indietro: niente resta scritto.
 * Il modulo la ripresenta come conferma e rimanda con `conferma_avviso_nota`.
 */
class NotaCreditoAvvisoDaConfermareException extends Exception
{
    public function report(): bool
    {
        return false;
    }
}
