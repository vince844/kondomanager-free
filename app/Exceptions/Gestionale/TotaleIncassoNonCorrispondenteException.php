<?php

namespace App\Exceptions\Gestionale;

use App\Helpers\MoneyHelper;

/**
 * L'incasso non quadra: `importo_totale ≠ somma delle righe + eccedenza`.
 *
 * Sostituisce nella beta.43 un `RuntimeException('Totale non corrispondente.')`, che aveva due
 * difetti oltre al nome generico. Il primo: `IncassoRateController::store()` non lo catturava,
 * quindi la guardia — pensata per proteggere la partita doppia — arrivava all'amministratore
 * come **pagina 500**, con la compilazione persa. Il secondo: non diceva *di quanto* fosse lo
 * scarto, e senza quel numero il difetto che la faceva scattare (l'eccedenza che contava il
 * credito due volte) è rimasto invisibile a lungo — l'errore sembrava un guasto, non un conto
 * sbagliato.
 *
 * La guardia resta esattamente com'era: non è lei il difetto, è la spia. Cambia solo che ora
 * si presenta come un errore di validazione e porta con sé i tre numeri, così se scatta di
 * nuovo si sa subito da che parte guardare.
 */
class TotaleIncassoNonCorrispondenteException extends IncassoNonRegistrabileException
{
    public function __construct(
        protected int $importoTotaleCents,
        protected int $sommaRigheCents,
        protected int $eccedenzaCents,
    ) {
        $atteso = $sommaRigheCents + $eccedenzaCents;
        $scarto = $importoTotaleCents - $atteso;

        parent::__construct(sprintf(
            'La registrazione non quadra e non è stata salvata: l\'importo incassato (%s) non '
            . 'corrisponde alla somma delle righe (%s) più l\'anticipo (%s), con uno scarto di '
            . '%s. Ricontrolla la distribuzione fra le rate prima di confermare.',
            MoneyHelper::format($this->importoTotaleCents),
            MoneyHelper::format($this->sommaRigheCents),
            MoneyHelper::format($this->eccedenzaCents),
            MoneyHelper::format($scarto)
        ));
    }

    public function getScartoCents(): int
    {
        return $this->importoTotaleCents - ($this->sommaRigheCents + $this->eccedenzaCents);
    }

    public function campo(): string
    {
        return 'importo_totale';
    }
}
