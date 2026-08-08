<?php

namespace App\Services\Import\Controlli;

enum StatoControllo: string
{
    /** Il problema c'è ancora. */
    case Aperto = 'aperto';

    /** Il sistema ha riguardato ed è a posto: nessuno ha dovuto spuntare niente. */
    case Risolto = 'risolto';

    /**
     * La finestra utile si è chiusa: non è stato sistemato, ma non si può più sistemare qui.
     * «Risolto» sarebbe falso, «aperto» sarebbe un invito verso una pagina dove non si fa più
     * niente — succede ai saldi assorbiti da un piano rate emesso.
     */
    case Superato = 'superato';

    /** L'amministratore ha dichiarato di averlo controllato. */
    case Spuntato = 'spuntato';

    /** Non lo riguarda, e l'ha detto. Una riga che non si può chiudere diventa una segnalazione. */
    case MessoDaParte = 'messo_da_parte';

    public function chiuso(): bool
    {
        return $this !== self::Aperto;
    }
}
