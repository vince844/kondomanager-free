<?php

namespace App\Services\Import\Canonical;

/**
 * Un capitolo di spesa con le sue voci — «AMMINISTRAZIONE» e i quattro conti che ci stanno sotto.
 *
 * ## Il totale non è ridondante
 *
 * Il file dichiara il totale del capitolo **oltre** alle sue voci, e lo tiene sulla riga
 * dell'ultima voce del gruppo invece che su quella del capitolo. Portarlo qui accanto alle voci
 * — invece di ricalcolarlo e basta — è la stessa scelta di `CanonicalSaldiApertura`: un totale
 * dichiarato dal file è una **verifica**, non un accessorio, e tenerlo in un altro posto
 * renderebbe possibile scrivere i capitoli senza aver controllato niente.
 *
 * ## Un capitolo senza voci non è un capitolo
 *
 * Nel file misurato «Spese personali» compare in colonna A come gli altri, ma **non ha voci** e
 * porta il totale sulla propria riga: si comporta da foglia, non da contenitore. Il canonico lo
 * rappresenta com'è — voci vuote — e la decisione su cosa farne sta nel parser e nel livello, non
 * qui: questo oggetto descrive il file, non il nostro archivio.
 */
final readonly class CanonicalCapitolo
{
    /**
     * @param  string  $nome  come lo stampa il file
     * @param  list<CanonicalVoceSpesa>  $voci
     * @param  int|null  $totaleDichiaratoCents  null se il file non lo porta per questo capitolo
     */
    public function __construct(
        public string $nome,
        public array $voci,
        public ?int $totaleDichiaratoCents = null,
    ) {}

    public function sommaVociCents(): int
    {
        return array_sum(array_map(fn (CanonicalVoceSpesa $v) => $v->importoCents, $this->voci));
    }

    /**
     * Quanto manca perché le voci facciano il totale che il capitolo dichiara.
     *
     * ⚠️ **`null` significa «non verificabile», e non va confuso con zero.** In PHP `null !== 0`
     * è vero: chi scrive `if ($capitolo->scartoCents() !== 0)` blocca anche tutti i capitoli che
     * un totale non ce l'hanno, cioè trasforma l'assenza di una verifica nel suo fallimento. Il
     * confronto si fa sempre su entrambi i lati — `!== null && !== 0` — come in
     * `LivelloSaldi::verificaQuadratura()`.
     */
    public function scartoCents(): ?int
    {
        if ($this->totaleDichiaratoCents === null || $this->voci === []) {
            return null;
        }

        return $this->totaleDichiaratoCents - $this->sommaVociCents();
    }

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'voci' => array_map(fn (CanonicalVoceSpesa $v) => $v->toArray(), $this->voci),
            'totale_cents' => $this->totaleDichiaratoCents ?? $this->sommaVociCents(),
        ];
    }
}
