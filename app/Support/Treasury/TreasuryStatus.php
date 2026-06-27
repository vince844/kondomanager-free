<?php

namespace App\Support\Treasury;

final readonly class TreasuryStatus
{
    public function __construct(
        public int     $condominioId,
        public ?int    $gestioneId,
        public int     $liquiditaTotaleCents,
        public int     $liquiditaVincolataCents,
        public int     $liquiditaDisponibileCents,
        public int     $uscitePredittiveCents,
        public int     $incassiAttesiCents,
        public int     $debitiPregressiScadutiCents,
        public int     $scenarioPessimisticoCents,
        public int     $scenarioOttimisticoCents,
        public ?string $giornoScopertoPrevisto,
        public int     $scopertoMaxCents,
        public string  $livello, // 'verde', 'giallo', 'rosso'
        public array   $fattureInScadenza,
        public array   $morositaImpattanti,
        public array   $azioniSuggerite,
        public array   $fattureSenzaScadenza = [],   // Anomalia §6.2: fatture aperte con data_scadenza NULL
        public bool    $hasFondoRiserva = false,     // Avviso MVP §6.4: fondi vincolati non ancora separati
    ) {}

    public function toArray(): array
    {
        return [
            'condominioId'                => $this->condominioId,
            'gestioneId'                  => $this->gestioneId,
            'liquiditaTotaleCents'        => $this->liquiditaTotaleCents,
            'liquiditaVincolataCents'     => $this->liquiditaVincolataCents,
            'liquiditaDisponibileCents'   => $this->liquiditaDisponibileCents,
            'uscitePredittiveCents'       => $this->uscitePredittiveCents,
            'incassiAttesiCents'          => $this->incassiAttesiCents,
            'debitiPregressiScadutiCents' => $this->debitiPregressiScadutiCents,
            'scenarioPessimisticoCents'   => $this->scenarioPessimisticoCents,
            'scenarioOttimisticoCents'    => $this->scenarioOttimisticoCents,
            'giornoScopertoPrevisto'      => $this->giornoScopertoPrevisto,
            'scopertoMaxCents'            => $this->scopertoMaxCents,
            'livello'                     => $this->livello,
            'fattureInScadenza'           => $this->fattureInScadenza,
            'morositaImpattanti'          => $this->morositaImpattanti,
            'azioniSuggerite'             => array_map(fn(AzioneSuggerita $a) => $a->toArray(), $this->azioniSuggerite),
            'fattureSenzaScadenza'        => $this->fattureSenzaScadenza,
            'hasFondoRiserva'             => $this->hasFondoRiserva,
        ];
    }
}
