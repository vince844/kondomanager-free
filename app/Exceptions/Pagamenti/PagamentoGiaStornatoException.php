<?php

namespace App\Exceptions\Pagamenti;

/**
 * Tentativo di stornare un pagamento già stornato.
 * Il ledger è immutabile: uno storno già eseguito non può essere "de-stornato".
 * Per correggere: registrare un nuovo pagamento.
 *
 * HTTP: 409 Conflict
 */
class PagamentoGiaStornatoException extends PagamentoException {}
