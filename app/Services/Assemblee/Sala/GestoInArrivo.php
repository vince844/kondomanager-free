<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Models\Assemblee\Assemblea;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Un gesto della sala come arriva al server, già controllato nella forma: l'assemblea della rotta, il numero, il tipo, i dati, la versione da cui è partito,
 * l'ora del gesto (UTC, corretta con lo scarto dell'orologio del dispositivo), chi lo manda. `forzato` è il gesto rimesso da «Scegli» fra due versioni: la
 * versione non si controlla più.
 */
final class GestoInArrivo
{
    /** @param array<string, mixed> $dati */
    public function __construct(
        public readonly Assemblea $assemblea,
        public readonly string $numero,
        public readonly string $tipo,
        public readonly array $dati,
        public readonly ?int $base,
        public readonly CarbonImmutable $segnatoIl,
        public readonly User $utente,
        public readonly bool $forzato = false,
    ) {}

    public function dato(string $chiave, mixed $diSerie = null): mixed
    {
        return data_get($this->dati, $chiave, $diSerie);
    }

    /** Un id dei dati: un intero positivo, o il gesto si rifiuta. */
    public function id(string $chiave): int
    {
        $valore = $this->dato($chiave);
        if (! is_numeric($valore) || (int) $valore <= 0) {
            throw ValidationException::withMessages(['gesto' => 'Il gesto non dice ' . $chiave . '.']);
        }

        return (int) $valore;
    }

    /** Un testo dei dati, senza spazi in testa e in coda; vuoto se manca. */
    public function testo(string $chiave): string
    {
        return trim((string) ($this->dato($chiave) ?? ''));
    }

    /** L'ora del gesto come si legge a Roma: «18:45». */
    public function ora(): string
    {
        return $this->segnatoIl->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i');
    }

    /** Il giorno del gesto a Roma: «2026-12-15». */
    public function giorno(): string
    {
        return $this->segnatoIl->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d');
    }

    /**
     * Il gesto è registrato dal foglio della sala: lo dice il gesto (la pagina in «Registra dal foglio della sala», anche a sala aperta), o la seduta aperta
     * dal foglio. Il diario scrive «registrato dal foglio della sala».
     */
    public function dalFoglio(?\App\Models\Assemblee\SedutaDellAssemblea $seduta = null): bool
    {
        return (bool) $this->dato('dal_foglio', false) || (bool) $seduta?->dal_foglio;
    }

    /** Il gesto con la versione non più da controllare: «Scegli» fra due versioni rimette la versione del dispositivo. */
    public function forzato(): self
    {
        return new self($this->assemblea, $this->numero, $this->tipo, $this->dati, $this->base, $this->segnatoIl, $this->utente, true);
    }

    /** La versione da controllare, o null quando non si controlla (gesto senza versione, o rimesso da «Scegli»). */
    public function baseDaControllare(): ?int
    {
        return $this->forzato ? null : $this->base;
    }
}
