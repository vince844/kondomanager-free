<?php

namespace App\Exceptions\Gestionale;

/**
 * La gestione dell'incasso non si può ricavare dalle rate, e l'amministratore non l'ha scelta — o ne ha scelta una che
 * non è fra quelle delle rate pagate (Coda 167, decisione 30.12 del 01/10/2026).
 *
 * Un incasso che paga rate di più gestioni, con il filtro «Gestione» vuoto, finiva nella gestione della quota con l'id
 * più basso: un criterio casuale, che poteva non coincidere con quello che il modulo mostrava (reperto S2 del rigiro
 * della Fase 1-bis della 1.11.0-beta.40). Ora la sceglie l'amministratore fra quelle delle rate pagate — col denaro o
 * coperte dal credito, perché la gestione intesta sia l'incasso sia la compensazione.
 */
class GestioneIncassoDaScegliereException extends IncassoNonRegistrabileException
{
    /**
     * @param  list<string>  $gestioniDelleRate  le gestioni delle rate che l'incasso paga, col denaro o col credito
     * @param  bool          $sceltaFuori        la gestione scelta non è fra quelle
     */
    public function __construct(
        protected array $gestioniDelleRate,
        protected bool $sceltaFuori = false,
    ) {
        parent::__construct($this->sceltaFuori
            ? sprintf(
                'La gestione scelta non è fra quelle delle rate che l\'incasso paga (%s): scegline una di queste.',
                implode(', ', $this->gestioniDelleRate),
            )
            : sprintf(
                'Questo incasso paga rate di più gestioni (%s): scegli a quale gestione va.',
                implode(', ', $this->gestioniDelleRate),
            ));
    }

    public function campo(): string
    {
        return 'gestione_id';
    }
}
