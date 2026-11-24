<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Import;

use InvalidArgumentException;

/**
 * Le quote in parti uguali, con due decimali, che fanno esattamente il totale: l'ultima prende il resto (Coda 599, 1.12.0-beta.14).
 *
 * Tre comproprietari senza quota sono 33,33 + 33,33 + 33,34 = 100, non 33,33 × 3 = 99,99: la colonna `anagrafica_immobile.quota` ha due decimali, e la
 * guardia delle quote somma per giorno. Il conto si fa in centesimi di punto, interi, e si riporta in punti solo alla fine.
 *
 * La usano `LivelloTitolarita` (le persone dello stesso ruolo sulla stessa unità senza quota nel file; le righe vuote accanto a quote scritte si dividono
 * il resto) e la divisione di un nome doppio in `ImportVerificaService`.
 */
final class PartiUguali
{
    /**
     * @param  int  $n  quante parti (almeno una)
     * @param  float  $totale  quanto devono fare insieme, in punti (100 = l'unità intera)
     * @return list<float>
     */
    public static function di(int $n, float $totale = 100.0): array
    {
        if ($n < 1) {
            throw new InvalidArgumentException('Le parti uguali chiedono almeno una parte.');
        }

        $centesimi = (int) round($totale * 100);
        $parte = intdiv($centesimi, $n);
        $parti = array_fill(0, $n, $parte);
        $parti[$n - 1] = $centesimi - $parte * ($n - 1);

        return array_map(fn (int $c) => $c / 100.0, $parti);
    }
}
