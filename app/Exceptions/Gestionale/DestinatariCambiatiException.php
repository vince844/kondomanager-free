<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Il cancello (2) della decisione 14: la risoluzione **temporale** dei titolari ha cambiato un
 * destinatario o un peso rispetto a quella atemporale — o il piano contiene una pregressa registrata
 * senza periodo (decisione 26, 1.11.0-beta.35) — e chi genera non ha ancora detto di averlo letto.
 *
 * Stesso schema di {@see ScopertiNonAccettatiException}: il controller la traduce in un avviso, la
 * pagina mostra **chi** cambia e **perché** (il gradino usato, i giorni), e si rigenera con
 * `accetta_destinatari` e una nota di almeno dieci caratteri, congelata nelle quote.
 */
class DestinatariCambiatiException extends Exception
{
    /** @param list<array<string,mixed>> $cambiamenti */
    public function __construct(protected array $cambiamenti)
    {
        parent::__construct('La risoluzione per periodo ha cambiato dei destinatari, o il piano contiene una pregressa senza periodo: serve una presa d\'atto esplicita.');
    }

    /** @return list<array<string,mixed>> */
    public function getCambiamenti(): array
    {
        return $this->cambiamenti;
    }

    public function report(): bool
    {
        return false;
    }
}
