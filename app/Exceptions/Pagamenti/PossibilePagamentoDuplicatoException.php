<?php

namespace App\Exceptions\Pagamenti;

/**
 * Rilevato possibile pagamento duplicato nella finestra temporale di 24 ore
 * (stessa fattura + stesso importo allocato + entro 24h).
 *
 * Bypassabile con flag conferma_duplicato_verificato = true.
 *
 * NOTA: la detection è volutamente conservativa in v1.9.1 (solo 24h, stesso importo)
 * per evitare falsi positivi su pagamenti parziali successivi legittimi.
 * Il sistema di scoring completo (multi-segnale) arriva in v1.16 (Treasury).
 *
 * HTTP: 409 Conflict (con istruzioni per override)
 */
class PossibilePagamentoDuplicatoException extends PagamentoException {}
