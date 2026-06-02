<?php

namespace App\Exceptions\Pagamenti;

/**
 * Tentativo di creare o stornare una scrittura contabile in un esercizio chiuso.
 *
 * IMPORTANTE — paradigma ledger-centric:
 * Questa eccezione viene lanciata sull'esercizio DELLA SCRITTURA ($data['esercizio_id']),
 * NON sull'esercizio della fattura. Una fattura di competenza 2025 può essere
 * pagata legittimamente nel 2026 anche se l'esercizio 2025 è chiuso.
 *
 * HTTP: 403 Forbidden
 */
class FiscalYearClosedException extends PagamentoException {}
