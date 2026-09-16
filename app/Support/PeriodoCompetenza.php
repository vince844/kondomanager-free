<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Un periodo di competenza, estremi inclusi.
 *
 * È l'unico primitivo temporale del riparto (progetto `docs/subentro_e_competenza_temporale.md`,
 * D2): una data puntuale — la delibera di una spesa straordinaria — è un periodo lungo un giorno,
 * `dal = al`. Due meccanismi separati sarebbero due copie della stessa aritmetica, e in questo
 * progetto le copie divergono.
 *
 * Nella 1.11.0-beta.30 (B1) il periodo esiste ed è accettato dalle firme, ma nessun chiamante di
 * produzione lo costruisce né lo passa; il risolutore lo riceve e lo ignora, di proposito. È B2 a
 * farlo entrare nei pesi.
 */
final class PeriodoCompetenza
{
    public readonly CarbonImmutable $dal;
    public readonly CarbonImmutable $al;

    public function __construct(CarbonImmutable|string $dal, CarbonImmutable|string $al)
    {
        $this->dal = self::giorno($dal);
        $this->al = self::giorno($al);

        if ($this->al->lt($this->dal)) {
            throw new InvalidArgumentException(
                "Periodo di competenza rovesciato: dal {$this->dal->toDateString()} al {$this->al->toDateString()}."
            );
        }
    }

    /** Il caso degenere di D2: la data di una delibera. */
    public static function puntuale(CarbonImmutable|string $giorno): self
    {
        return new self($giorno, $giorno);
    }

    /** Giorni del periodo, estremi inclusi: un periodo puntuale vale 1. */
    public function giorni(): int
    {
        return (int) $this->dal->diffInDays($this->al) + 1;
    }

    public function contiene(CarbonImmutable|string $giorno): bool
    {
        $g = self::giorno($giorno);

        return $g->gte($this->dal) && $g->lte($this->al);
    }

    /**
     * Giorni in comune con un altro periodo, estremi inclusi; 0 se non si toccano.
     * È il numeratore del pro rata di B2: `quota × giorni di sovrapposizione`.
     */
    public function giorniDiSovrapposizione(self $altro): int
    {
        $inizio = $this->dal->max($altro->dal);
        $fine = $this->al->min($altro->al);

        return $fine->lt($inizio) ? 0 : (int) $inizio->diffInDays($fine) + 1;
    }

    /**
     * Una data di calendario, senza ora e senza fuso: si normalizza in UTC perché il conteggio dei
     * giorni non dipenda da `app.timezone` né da come le date arrivano: due mezzanotti locali portate
     * in UTC a cavallo del cambio d'ora distano 47 ore, e `diffInDays` dà 1,96 invece di 2 — `(int)`
     * lo tronca a 1. Parsando la sola data in UTC il diff è sempre intero. Oggi l'app è in UTC;
     * questa riga vale per quando non lo sarà più.
     */
    private static function giorno(CarbonImmutable|string $valore): CarbonImmutable
    {
        $data = $valore instanceof CarbonImmutable ? $valore->toDateString() : (string) $valore;

        return CarbonImmutable::parse($data, 'UTC')->startOfDay();
    }

    public function toArray(): array
    {
        return ['dal' => $this->dal->toDateString(), 'al' => $this->al->toDateString()];
    }
}
