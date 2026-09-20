<?php

namespace App\Services\Riparto;

use App\Enums\NaturaGestione;
use App\Support\InsiemePeriodi;

/**
 * La risposta di `RisolutoreCompetenza`: quale periodo di competenza vale per una spesa, e da quale
 * gradino della cascata viene.
 *
 * È un valore: si passa al motore, si mostra in anteprima, si congela per riga in `righe_riparto`
 * (decisione 15). Quando `richiedeDelibera` è vero non c'è un periodo: lo straordinario senza data della
 * delibera **non ripiega** (decisione 12), e chi riceve questo esito si ferma e chiede — non deduce.
 */
final class EsitoCompetenza
{
    public function __construct(
        public readonly NaturaGestione $natura,
        /** `null` solo quando `richiedeDelibera` è vero. */
        public readonly ?InsiemePeriodi $periodi,
        /** `null` solo quando `richiedeDelibera` è vero. */
        public readonly ?GradinoCompetenza $gradino,
        /** Straordinaria senza competenza dichiarata e senza delibera: il motore deve fermarsi. */
        public readonly bool $richiedeDelibera = false,
        /** `piani_rate.tipo` non concorda con `gestioni.tipo`: fa fede la natura, ma si dichiara (decisione 11). */
        public readonly bool $divergenzaTipoPiano = false,
        /** Ordinaria con una competenza dichiarata sulla fattura, che qui non guida le rate (decisione 19). */
        public readonly bool $competenzaFatturaIgnorata = false,
    ) {
    }

    public function risolto(): bool
    {
        return ! $this->richiedeDelibera && $this->periodi !== null && $this->gradino !== null;
    }

    /**
     * Lo stesso esito, con la dichiarazione che una competenza scritta sulla fattura è stata letta e non
     * usata (decisione 19: su un capitolo ordinario la competenza della fattura non guida le rate).
     */
    public function conFatturaIgnorata(): self
    {
        return new self($this->natura, $this->periodi, $this->gradino, $this->richiedeDelibera, $this->divergenzaTipoPiano, true);
    }

    /** Il riepilogo che si congela accanto ai numeri (anteprima, `righe_riparto`, legenda). */
    public function toArray(): array
    {
        return [
            'natura' => $this->natura->value,
            'gradino' => $this->gradino?->value,
            'periodi' => $this->periodi?->toArray(),
            'richiede_delibera' => $this->richiedeDelibera,
            'divergenza_tipo_piano' => $this->divergenzaTipoPiano,
            'competenza_fattura_ignorata' => $this->competenzaFatturaIgnorata,
        ];
    }
}
