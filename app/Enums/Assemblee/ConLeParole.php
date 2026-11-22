<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/**
 * Le parole di un enum delle assemblee, come le dice la pagina (§6.2 del progetto della tappa 2): `parola()` per un valore, `parole()` per il menu e per
 * il registro delle modifiche (`['scelta', Enum::parole()]`), `valori()` per le regole di validazione.
 */
trait ConLeParole
{
    /** @return array<string, string> valore => parola */
    public static function parole(): array
    {
        $parole = [];
        foreach (self::cases() as $caso) {
            $parole[$caso->value] = $caso->parola();
        }

        return $parole;
    }

    /** @return list<string> */
    public static function valori(): array
    {
        return array_map(fn (self $caso) => $caso->value, self::cases());
    }

    /** La parola di un valore grezzo, o il valore stesso se non è dell'enum (un valore di una tappa dopo). */
    public static function parolaDi(?string $valore): ?string
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        return self::tryFrom($valore)?->parola() ?? $valore;
    }
}
