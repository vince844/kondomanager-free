<?php

namespace App\Exceptions\Pagamenti;

/**
 * Nessun esercizio aperto disponibile per registrare lo storno cross-esercizio.
 * Si verifica quando l'esercizio del pagamento originale è chiuso E non esiste
 * un esercizio corrente aperto per quel condominio.
 *
 * HTTP: 409 Conflict
 */
class NessunEsercizioApertoException extends PagamentoException {}
