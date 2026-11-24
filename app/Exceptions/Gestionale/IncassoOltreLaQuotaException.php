<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

use App\Helpers\MoneyHelper;

/**
 * Su una riga dell'incasso l'amministratore ha messo più di quanto il pagante deve su quella rata (Coda 754 (7)).
 *
 * Cercando per unità la riga raccoglie le quote di tutti quelli che hanno l'unità: Paolo, compratore, deve € 100,00 sulla rata 1, Giulia, venditrice,
 * € 300,00, e la riga porta € 400,00. Il motore salda solo le quote del pagante (`StoreIncassoRateAction::execute`, il ciclo delle righe): la parte che
 * restava non aveva dove andare e si perdeva, la scrittura usciva con dare € 400,00 e avere € 100,00, e il controllo della partita doppia la fermava con
 * un'eccezione semplice, cioè una pagina 500 che buttava via la distribuzione fatta a mano. La guardia vicina (`DebitoNonDelPaganteException`) scatta
 * solo se il pagante non ha nessuna quota sulla rata.
 *
 * Sale dentro la transazione, al posto della parte che si perdeva: niente resta scritto, e il controller la mostra nella fascia «Incasso non registrato»
 * come le altre guardie della famiglia. La frase dice quanto deve il pagante, di chi è il resto e come registrarlo.
 */
class IncassoOltreLaQuotaException extends IncassoNonRegistrabileException
{
    /**
     * @param  string  $pagante  il nome del pagante
     * @param  int|string  $numeroRata  il numero della rata della riga
     * @param  int  $deveCents  quanto il pagante doveva su quella rata prima dell'incasso
     * @param  int  $inseritoCents  l'importo della riga
     * @param  list<string>  $altri  chi altro ha una quota ancora da pagare sulla stessa rata e sulla stessa unità
     * @param  int  $altriDevonoCents  quanto devono ancora, insieme
     * @param  ?string  $chiHaVersato  chi ha versato per il pagante («Versato da»), se è un'altra persona
     */
    public function __construct(
        protected string $pagante,
        protected int|string $numeroRata,
        protected int $deveCents,
        protected int $inseritoCents,
        protected array $altri = [],
        protected int $altriDevonoCents = 0,
        protected ?string $chiHaVersato = null,
    ) {
        $versante = $this->chiHaVersato ?? $this->pagante;
        $inPiuCents = max(0, $this->inseritoCents - $this->deveCents);
        $deve = MoneyHelper::format($this->deveCents);
        $inPiu = MoneyHelper::format($inPiuCents);
        $apertura = ($this->deveCents > 0
                ? "{$this->pagante} deve ancora {$deve} sulla rata n. {$this->numeroRata}"
                : "{$this->pagante} non deve più niente sulla rata n. {$this->numeroRata}")
            . ' e hai inserito ' . MoneyHelper::format($this->inseritoCents) . '.';

        if (count($this->altri) === 1 && $inPiuCents <= $this->altriDevonoCents) {
            $altro = $this->altri[0];
            parent::__construct("{$apertura} I {$inPiu} in più sono di {$altro}, che ha una quota su questa rata: registra {$deve} a nome di "
                . "{$this->pagante} e {$inPiu} a nome di {$altro}, con «Versato da» {$versante} se il versamento è suo.");

            return;
        }

        if ($this->altri !== []) {
            parent::__construct("{$apertura} I {$inPiu} in più non sono suoi: su questa rata hanno una quota anche " . implode(', ', $this->altri)
                . ', che devono ancora ' . MoneyHelper::format($this->altriDevonoCents) . ". Registra {$deve} a nome di {$this->pagante} e il resto a nome "
                . "di ciascuno di loro, con «Versato da» {$versante} se il versamento è suo.");

            return;
        }

        parent::__construct($apertura . ($this->deveCents > 0 ? " Sulla riga scrivi {$deve}" : ' Togli l\'importo dalla riga')
            . ": con lo stesso importo versato, i {$inPiu} in più diventano la parte in più (eccedenza), un credito di {$versante}.");
    }

    public function campo(): string
    {
        return 'importo_totale';
    }
}
