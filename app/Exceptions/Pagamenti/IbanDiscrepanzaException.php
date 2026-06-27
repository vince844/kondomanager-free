<?php

namespace App\Exceptions\Pagamenti;

/**
 * L'IBAN di destinazione inserito differisce dall'IBAN storico nell'anagrafica
 * del fornitore (Sentinella Anti-Frode).
 *
 * Bypassabile con flag iban_confermato_manualmente = true, che registra
 * nell'audit log chi ha confermato la discrepanza e quando.
 *
 * ATTENZIONE: le truffe Man-in-the-Middle con sostituzione IBAN su fatture PDF
 * sono il principale vettore di frode nel settore condominiale italiano.
 *
 * HTTP: 409 Conflict (con istruzioni per override)
 */
class IbanDiscrepanzaException extends PagamentoException {}
