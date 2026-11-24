<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support;

/**
 * Il piano dell'edificio come lo legge un condòmino sulla stampa.
 *
 * Il campo «piano» dell'unità è testo libero: «1» o «T» dal modulo e dall'importatore, «Primo piano» o «Piano terra» scritti per
 * esteso. La parola «piano» si premette solo se il valore non la porta già, così non esce «Piano Piano terra» né «int. 3 · 1».
 * La regola è una sola e la usano le quattro stampe (la ripartizione spese, lo scadenziario per unità, il riparto per tabella e
 * quello per capitolo).
 */
final class EtichettaDelPiano
{
    /** L'etichetta da stampare, o null se l'unità non ha il campo. */
    public static function per(?string $piano): ?string
    {
        $piano = trim((string) $piano);

        if ($piano === '') {
            return null;
        }

        return str_contains(mb_strtolower($piano), 'piano') ? $piano : 'piano '.$piano;
    }
}
