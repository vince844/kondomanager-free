<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Un saldo intestato all'unità non ha nessuno su cui cadere.
 *
 * Nasce nella beta.43 per chiudere il peggiore dei tre modi in cui un pregresso poteva
 * sparire in silenzio: se sull'immobile non c'è nessun titolare di un diritto reale, la
 * collection usciva vuota, il `foreach` non girava e il saldo svaniva **senza eccezione e
 * senza log** — mentre il lucchetto si chiudeva lo stesso e `gestioni.nota_saldo` registrava
 * un importo «processato» che non esisteva in nessuna quota.
 *
 * L'ADR-001 lo prevedeva fin dall'inizio («Proprietari assenti → ALERT BLOCCANTE») e non era
 * mai stato costruito.
 *
 * **Perché bloccare e non ripiegare.** Il ripiego possibile sarebbe addebitare a chiunque sia
 * attivo sull'unità — cioè l'inquilino, che è esattamente ciò che questa beta toglie. Un
 * pregresso senza titolare non è un problema di calcolo: è un'anagrafica incompleta, e si
 * corregge censendo chi possiede l'unità. Il messaggio deve portarci l'amministratore, non
 * nascondergli la domanda.
 */
class SaldoSolidaleSenzaTitolareException extends Exception
{
    public function __construct(
        protected int $saldoId,
        protected ?int $immobileId,
        protected string $nomeImmobile,
        protected int $importoCents,
    ) {
        parent::__construct(
            "Il pregresso dell'unità «{$nomeImmobile}» non può essere ripartito: "
            . 'su quell\'unità non risulta attivo nessun proprietario, nudo proprietario o '
            . 'usufruttuario. Censisci chi possiede l\'unità, oppure usa il riparto manuale '
            . 'per indicare tu chi deve farsene carico.'
        );
    }

    public function getSaldoId(): int
    {
        return $this->saldoId;
    }

    public function getImmobileId(): ?int
    {
        return $this->immobileId;
    }

    public function getImportoCents(): int
    {
        return $this->importoCents;
    }

    public function report(): bool
    {
        return false;
    }
}
