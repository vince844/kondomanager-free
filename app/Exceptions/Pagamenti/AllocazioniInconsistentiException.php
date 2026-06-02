<?php

namespace App\Exceptions\Pagamenti;

/**
 * Le allocazioni nella stessa operazione di pagamento puntano a fatture
 * di fornitori o condomini diversi. Un pagamento deve sempre riguardare
 * un unico fornitore per un unico condominio.
 *
 * HTTP: 422 Unprocessable Entity
 */
class AllocazioniInconsistentiException extends PagamentoException {}
