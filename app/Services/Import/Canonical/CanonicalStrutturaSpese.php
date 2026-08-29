<?php

namespace App\Services\Import\Canonical;

/**
 * La struttura delle spese letta da un bilancio consuntivo, **con il totale su cui deve quadrare**.
 *
 * Stessa forma di `CanonicalSaldiApertura`, e per la stessa ragione: le righe e il totale
 * viaggiano insieme perché il totale è la condizione perché quelle righe possano entrare, non
 * un'informazione a fianco.
 *
 * ## Le spese personali sono qui, e non fra i capitoli
 *
 * ⚠️ **Il file le stampa come un capitolo, ma non lo sono — e importarle conterebbe due volte lo
 * stesso denaro.** Misurato sul corpus di un amministratore vero: «Spese personali» vale
 * −258,76 nel bilancio, e la colonna «Movimenti personali» del riparto dello stesso condominio
 * somma **esattamente** −258,76. Sono lo stesso denaro visto dai due lati: il bilancio ne dà il
 * totale, il riparto lo spezza per unità. E quella cifra per unità confluisce in «Totale
 * gestione» → «Saldo finale», cioè **in ciò che importiamo già come saldo di apertura**.
 *
 * Scriverle anche come capitolo significherebbe rappresentarle due volte, e per giunta ripartite
 * per millesimi: farebbe pagare a tutti quello che devono singoli condòmini.
 *
 * Restano quindi qui — dentro la quadratura, perché il totale del file le comprende — e non
 * diventano nessun conto. È lo stesso trattamento che `RipartoConsuntivoParser` riserva alla riga
 * `Arrotondamenti`: si usa, non si importa.
 */
final readonly class CanonicalStrutturaSpese
{
    /**
     * @param  list<CanonicalCapitolo>  $capitoli
     * @param  int|null  $totaleDichiaratoCents  la riga «TOTALE», null se la stampa non la porta
     * @param  int  $spesePersonaliCents  parte del totale, ma non un capitolo
     */
    public function __construct(
        public array $capitoli,
        public ?int $totaleDichiaratoCents = null,
        public int $spesePersonaliCents = 0,
    ) {}

    public function sommaCapitoliCents(): int
    {
        return array_sum(array_map(fn (CanonicalCapitolo $c) => $c->totaleDichiaratoCents ?? $c->sommaVociCents(), $this->capitoli));
    }

    /**
     * Quanto manca perché i capitoli, più le spese personali, facciano il totale dichiarato.
     *
     * ⚠️ `null` è «non verificabile», non «quadra»: vedi la nota in `CanonicalCapitolo::scartoCents()`.
     */
    public function scartoCents(): ?int
    {
        if ($this->totaleDichiaratoCents === null) {
            return null;
        }

        return $this->totaleDichiaratoCents - ($this->sommaCapitoliCents() + $this->spesePersonaliCents);
    }

    public function quadra(): bool
    {
        return $this->scartoCents() === 0;
    }

    /** @return list<CanonicalVoceSpesa> tutte le voci, di tutti i capitoli */
    public function voci(): array
    {
        return array_merge(...array_map(fn (CanonicalCapitolo $c) => $c->voci, $this->capitoli)) ?: [];
    }
}
