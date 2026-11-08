<?php

namespace App\Exceptions\Gestionale;

/**
 * L'incasso da terzi (o il suo storno) è stato chiesto in una forma che il giornale non ammette: una voce che non è di entrata o non è del
 * condominio, una cassa che non è reale, uno storno di una scrittura che non è un incasso da terzi o che è già stornata.
 *
 * Le stesse regole stanno nella richiesta, sul campo; qui valgono anche quando l'azione è chiamata da un servizio o da un test.
 *
 * HTTP: 422 (violazione di una regola di dominio, non di autorizzazione).
 */
class IncassoDaTerziNonAmmessoException extends \DomainException {}
