<?php

namespace App\Support;

use ArrayIterator;
use Carbon\CarbonImmutable;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * Un insieme ordinato di periodi di competenza che non si sovrappongono.
 *
 * Decisione 20 del progetto `docs/subentro_e_competenza_temporale.md` (§9): la competenza di un
 * capitolo ordinario non è un intervallo solo. Il riscaldamento in un esercizio solare è **due tratti**
 * — dal 1° gennaio al 15 aprile e dal 15 ottobre al 31 dicembre, zona E (DPR 74/2013) — e un solo
 * `PeriodoCompetenza` non lo rappresenta. Il pro rata di B2 **somma** le sovrapposizioni tratto per
 * tratto: chi vende il 30 aprile ha pagato il primo tratto intero e nulla del secondo.
 *
 * `PeriodoCompetenza` resta l'intervallo elementare; questo è il suo contenitore. Un insieme vuoto non
 * esiste: «nessuna riga in `competenze_capitolo`» significa «periodo della gestione», e a deciderlo è
 * `RisolutoreCompetenza`, non un insieme senza tratti che qualcuno finirebbe per contare zero giorni.
 *
 * @implements IteratorAggregate<int, PeriodoCompetenza>
 */
final class InsiemePeriodi implements Countable, IteratorAggregate
{
    /** @var list<PeriodoCompetenza> ordinati per `dal`, senza sovrapposizioni */
    private readonly array $periodi;

    public function __construct(PeriodoCompetenza ...$periodi)
    {
        if ($periodi === []) {
            throw new InvalidArgumentException('Un insieme di periodi di competenza deve avere almeno un tratto.');
        }

        usort($periodi, fn (PeriodoCompetenza $a, PeriodoCompetenza $b) => $a->dal <=> $b->dal);

        foreach ($periodi as $i => $p) {
            if ($i > 0 && $p->dal->lte($periodi[$i - 1]->al)) {
                throw new InvalidArgumentException(sprintf(
                    'Tratti di competenza sovrapposti: %s–%s e %s–%s.',
                    $periodi[$i - 1]->dal->toDateString(), $periodi[$i - 1]->al->toDateString(),
                    $p->dal->toDateString(), $p->al->toDateString(),
                ));
            }
        }

        $this->periodi = array_values($periodi);
    }

    /** Il caso comune: un solo tratto. */
    public static function uno(PeriodoCompetenza $periodo): self
    {
        return new self($periodo);
    }

    /** @return list<PeriodoCompetenza> */
    public function periodi(): array
    {
        return $this->periodi;
    }

    /** Il primo giorno del primo tratto. */
    public function dal(): CarbonImmutable
    {
        return $this->periodi[0]->dal;
    }

    /** L'ultimo giorno dell'ultimo tratto. */
    public function al(): CarbonImmutable
    {
        return $this->periodi[count($this->periodi) - 1]->al;
    }

    /** Giorni complessivi, estremi inclusi, sommati sui tratti. */
    public function giorni(): int
    {
        return array_sum(array_map(fn (PeriodoCompetenza $p) => $p->giorni(), $this->periodi));
    }

    public function contiene(CarbonImmutable|string $giorno): bool
    {
        foreach ($this->periodi as $p) {
            if ($p->contiene($giorno)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Giorni in comune con un periodo o con un altro insieme, sommati tratto per tratto.
     * È il numeratore del pro rata di B2 quando la competenza è a più tratti.
     */
    public function giorniDiSovrapposizione(PeriodoCompetenza|self $altro): int
    {
        $altri = $altro instanceof self ? $altro->periodi : [$altro];
        $totale = 0;

        foreach ($this->periodi as $mio) {
            foreach ($altri as $suo) {
                $totale += $mio->giorniDiSovrapposizione($suo);
            }
        }

        return $totale;
    }

    /**
     * L'insieme ritagliato su un tratto: i tratti che lo toccano, ciascuno ridotto alla parte in comune; `null` se
     * nessuno lo tocca. È come il conguaglio del passaggio divide una riga del riparto (migrazione 11): la sua
     * competenza ∩ il tratto di titolarità che quella riga copre.
     */
    public function intersezione(PeriodoCompetenza $tratto): ?self
    {
        $parti = [];
        foreach ($this->periodi as $p) {
            $i = $p->intersezione($tratto);
            if ($i !== null) {
                $parti[] = $i;
            }
        }

        return $parti === [] ? null : new self(...$parti);
    }

    /**
     * L'insieme senza i giorni del tratto dato; `null` se non ne resta nessuno (1.11.0-beta.34, Fase 1-bis R5). Serve a
     * ricostruire i giorni di una riga di ripiego, il cui tratto congelato è l'estensione di un insieme di buchi.
     */
    public function meno(PeriodoCompetenza $tratto): ?self
    {
        $parti = [];
        foreach ($this->periodi as $p) {
            if ($p->intersezione($tratto) === null) {
                $parti[] = $p;
                continue;
            }
            if ($p->dal->lt($tratto->dal)) {
                $parti[] = new PeriodoCompetenza($p->dal, $tratto->dal->subDay());
            }
            if ($p->al->gt($tratto->al)) {
                $parti[] = new PeriodoCompetenza($tratto->al->addDay(), $p->al);
            }
        }

        return $parti === [] ? null : new self(...$parti);
    }

    /**
     * L'unione con un altro insieme: i tratti che si sovrappongono o si toccano diventano uno (1.11.0-beta.47). Serve al prospetto
     * degli oneri accessori per contare i giorni di una voce che somma pezzi della stessa persona: gli stessi giorni non si contano
     * due volte, e due tratti disgiunti sì.
     */
    public function unione(self $altro): self
    {
        $tutti = [...$this->periodi, ...$altro->periodi];
        usort($tutti, fn (PeriodoCompetenza $a, PeriodoCompetenza $b) => $a->dal <=> $b->dal);
        $fusi = [];
        foreach ($tutti as $p) {
            $n = count($fusi);
            if ($n > 0 && $p->dal->lte($fusi[$n - 1]->al->addDay())) {
                $fusi[$n - 1] = new PeriodoCompetenza($fusi[$n - 1]->dal, $p->al->gt($fusi[$n - 1]->al) ? $p->al : $fusi[$n - 1]->al);
                continue;
            }
            $fusi[] = $p;
        }

        return new self(...$fusi);
    }

    /** @return list<array{dal: string, al: string}> */
    public function toArray(): array
    {
        return array_map(fn (PeriodoCompetenza $p) => $p->toArray(), $this->periodi);
    }

    public function count(): int
    {
        return count($this->periodi);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->periodi);
    }
}
