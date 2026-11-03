<?php

namespace App\Exceptions\Gestionale;

/**
 * Decisione 41 (1.11.0-beta.42): l'incasso tocca una quota di un piano che deve ancora seguire un passaggio di titolarità —
 * registrato dopo la generazione, quando il piano si poteva ancora ricalcolare, o (beta.51) annullato dopo un ricalcolo che lo
 * comprendeva — e il piano non è stato ricalcolato. Un incasso o un credito usato lo fermerebbe (decisione 34.1): il ricalcolo si
 * rifiuterebbe, e le quote resterebbero con la titolarità sbagliata. Si ricalcola prima, poi si registra l'incasso.
 *
 * Estende la base della famiglia, così `IncassoRateController::store()` torna al modulo con il motivo invece della pagina 500.
 */
class PianoDaRicalcolareException extends IncassoNonRegistrabileException
{
    public function __construct(string $messaggio)
    {
        parent::__construct($messaggio);
    }

    public function campo(): string
    {
        return 'dettaglio_pagamenti';
    }
}
