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
    ) {}

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
