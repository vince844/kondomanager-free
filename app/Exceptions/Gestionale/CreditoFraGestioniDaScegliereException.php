<?php

namespace App\Exceptions\Gestionale;

/**
 * Il credito di una gestione coprirebbe righe di un'altra, e l'amministratore non ha detto se può (Coda 167, decisione
 * 30.11 del 01/10/2026).
 *
 * Il credito di una gestione copre da sé solo i debiti della sua gestione. Che passi a un'altra — l'ordinaria che paga
 * il tetto — è una scelta dell'amministratore: il modulo la chiede, senza niente di già scelto, e il server la pretende
 * quando cambierebbe il risultato (`PianoCreditoIncasso`, `serve_scelta`). Prima il motore faceva il travaso da solo e
 * scriveva «confermata dall'amministratore» anche quando nessuno aveva confermato (reperti S1 e S5 del rigiro della
 * Fase 1-bis della 1.11.0-beta.40). L'incasso si ferma prima che resti scritto qualcosa.
 */
class CreditoFraGestioniDaScegliereException extends IncassoNonRegistrabileException
{
    /**
     * @param  list<string>  $gestioniDelCredito  le gestioni del credito impegnato
     * @param  list<string>  $altreGestioni       le gestioni delle righe che il credito coprirebbe passando di gestione
     */
    public function __construct(
        protected array $gestioniDelCredito,
        protected array $altreGestioni,
    ) {
        parent::__construct(sprintf(
            'Il credito di %s coprirebbe anche rate di %s: scegli se resta sulla sua gestione o passa anche alle altre.',
            implode(', ', $this->gestioniDelCredito) ?: 'una gestione',
            implode(', ', $this->altreGestioni) ?: 'un\'altra gestione',
        ));
    }

    public function campo(): string
    {
        return 'credito_fra_gestioni';
    }
}
