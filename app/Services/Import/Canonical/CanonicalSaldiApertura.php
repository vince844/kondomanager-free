<?php

namespace App\Services\Import\Canonical;

/**
 * I saldi di apertura letti da un riparto, **con il totale su cui devono quadrare**.
 *
 * Le righe e il totale viaggiano insieme e non separatamente, ed è una scelta: il totale non è
 * un accessorio informativo, è la condizione perché quelle righe possano entrare. Tenerli in
 * due posti diversi renderebbe possibile scrivere i saldi senza aver verificato niente —
 * possibile per distrazione, cioè prima o poi.
 */
final readonly class CanonicalSaldiApertura
{
    /**
     * @param  list<CanonicalSaldo>  $righe
     * @param  int|null  $totaleRiferimentoCents  null se la stampa non porta il TOTALE COMPLESSIVO
     * @param  int  $arrotondamentiCents  la riga «Arrotondamenti», che fa parte del totale
     */
    public function __construct(
        public array $righe,
        public ?int $totaleRiferimentoCents,
        public int $arrotondamentiCents = 0,
        /**
         * Da dove vengono queste righe, in parole da mostrare — «riparto consuntivo», «foglio dei
         * saldi».
         *
         * Serve all'anteprima, che fino alla beta.5 scriveva «posizioni dal riparto consuntivo»
         * in modo fisso: con il modello compilato a mano quella frase nominava un file che
         * l'amministratore non ha mai avuto, ed è precisamente il file che non poteva esportare.
         */
        public ?string $fonte = null,
    ) {}

    /**
     * C'è un totale con cui confrontarsi?
     *
     * ⚠️ Distinto da `quadra()`, e la distinzione non è accademica: senza totale `scartoCents()`
     * è `null`, e `null === 0` in PHP è **falso**, quindi `quadra()` risponde «no». Detto a
     * schermo diventava «non quadrano» in rosso su un lotto in cui non c'era niente da quadrare —
     * la forma peggiore di allarme, quella che accusa chi non ha sbagliato.
     */
    public function verificabile(): bool
    {
        return $this->totaleRiferimentoCents !== null;
    }

    public function sommaRigheCents(): int
    {
        return array_sum(array_map(fn (CanonicalSaldo $s) => $s->importoCents, $this->righe));
    }

    /**
     * Quanto manca perché le righe facciano il totale dichiarato dalla stampa.
     *
     * Zero significa che l'import può procedere. Qualunque altro numero significa che qualcosa
     * non è stato letto, o è stato letto due volte, o il file è stato modificato a mano dopo
     * l'esportazione — e in tutti e tre i casi i saldi non devono entrare.
     */
    public function scartoCents(): ?int
    {
        if ($this->totaleRiferimentoCents === null) {
            return null;
        }

        return $this->totaleRiferimentoCents - ($this->sommaRigheCents() + $this->arrotondamentiCents);
    }

    public function quadra(): bool
    {
        return $this->scartoCents() === 0;
    }
}
