<?php

namespace App\Services\Backup\Support;

/**
 * Budget di tempo di un singolo step del backup.
 *
 * Ogni step HTTP lavora finché il budget non è esaurito, poi salva il
 * checkpoint e restituisce il controllo al frontend, che richiede lo step
 * successivo. Misurato con microtime reale, non con tick di CPU.
 */
final class StepBudget
{
    private float $startedAt;

    public function __construct(private readonly float $seconds)
    {
        $this->startedAt = microtime(true);
    }

    public function elapsed(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function remaining(): float
    {
        return max(0.0, $this->seconds - $this->elapsed());
    }

    public function exceeded(): bool
    {
        return $this->elapsed() >= $this->seconds;
    }
}
