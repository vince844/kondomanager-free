<?php

namespace App\Exceptions\Pagamenti;

/**
 * Una o più fatture nelle allocazioni hanno stato_approvazione != 'approvata'.
 * Le fatture in stato di verifica/contestazione non sono pagabili.
 * Art. 1135 c.c. — l'assemblea deve deliberare le spese prima del pagamento.
 *
 * HTTP: 422 Unprocessable Entity
 */
class FatturaNonApprovataException extends PagamentoException {}
