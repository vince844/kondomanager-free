<?php

namespace App\Exceptions\Gestionale;

use App\Helpers\MoneyHelper;

/**
 * Chi ha versato per un altro ha pagato più del debito, e nel condominio non ha rate emesse su cui la differenza possa
 * diventare un suo credito (Coda 167, decisione 30.3). Le rate in bozza non contano: un pagamento lì bloccherebbe il
 * ricalcolo del loro piano.
 *
 * La parte in più di un versamento resta a chi l'ha versata, come il programma ha sempre fatto: diventa un suo credito,
 * usabile sulle sue rate. Chi versa per un altro può però non avere rate qui — il figlio che paga per la madre,
 * associato al condominio senza unità — e allora quel credito non avrebbe dove stare. Il forum (Fresco, 30/09/2026)
 * chiede a chi ha pagato dove metterla: il programma non può chiederglielo, quindi l'incasso si registra per il solo
 * debito e la differenza si restituisce fuori dal programma.
 */
class ParteInPiuSenzaRateException extends IncassoNonRegistrabileException
{
    public function __construct(
        protected string $chiHaVersato,
        protected int $parteInPiuCents,
        protected int $debitoCents,
    ) {
        parent::__construct(sprintf(
            '%s non ha rate emesse in questo condominio: la parte in più (%s) non può diventare un suo credito. '
            . 'Registra solo il debito, %s, e restituisci la differenza fuori dal programma.',
            $this->chiHaVersato,
            MoneyHelper::format($this->parteInPiuCents),
            MoneyHelper::format($this->debitoCents),
        ));
    }

    public function campo(): string
    {
        return 'importo_totale';
    }
}
