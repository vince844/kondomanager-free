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
        // Dalla 1.12.0-beta.3 (decisione 217 D4): gli incassi attesi divisi, le persone oltre le prime cinque, lo scaduto per fasce.
        public int     $incassiScadutiCents = 0,
        public int     $incassiInScadenzaCents = 0,
        public int     $morositaAltri = 0,
        public array   $scadutoPerFasce = ['1_30' => 0, '31_90' => 0, 'oltre_90' => 0],
        // Dalla 1.12.0-beta.4: l'elenco delle rate scadute del condominio, per il «Vedi tutte» del blocco delle persone.
        public ?array  $elencoRateScadute = null,
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
            'incassiScadutiCents'         => $this->incassiScadutiCents,
            'incassiInScadenzaCents'      => $this->incassiInScadenzaCents,
            'debitiPregressiScadutiCents' => $this->debitiPregressiScadutiCents,
            'scenarioPessimisticoCents'   => $this->scenarioPessimisticoCents,
            'scenarioOttimisticoCents'    => $this->scenarioOttimisticoCents,
            'giornoScopertoPrevisto'      => $this->giornoScopertoPrevisto,
            'scopertoMaxCents'            => $this->scopertoMaxCents,
            'livello'                     => $this->livello,
            'fattureInScadenza'           => $this->fattureInScadenza,
            'morositaImpattanti'          => $this->morositaImpattanti,
            'morositaAltri'               => $this->morositaAltri,
            'scadutoPerFasce'             => $this->scadutoPerFasce,
            'elencoRateScadute'           => $this->elencoRateScadute,
            'azioniSuggerite'             => array_map(fn(AzioneSuggerita $a) => $a->toArray(), $this->azioniSuggerite),
            'fattureSenzaScadenza'        => $this->fattureSenzaScadenza,
            'hasFondoRiserva'             => $this->hasFondoRiserva,
        ];
    }
}
