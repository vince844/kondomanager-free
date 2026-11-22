<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\Seguito\Effetti\SuiLavori;

/**
 * Il fondo speciale in testa al piano (decisione 622 (2), la scena 3 della bozza approvata con la decisione 623): «Fondo speciale: € 9.000,00 chiesti su
 * € 14.500,00 deliberati.», con le parole della legge (art. 1135 n. 4 c.c.). **Non blocca, non ha «Ignora»**, e sparisce da sola quando i piani della
 * delibera arrivano ai lavori: il conto è quello del seguito ({@see SuiLavori::fondoSpeciale()}), la somma dei piani della delibera e della sua gestione.
 *
 * La delibera è quella del piano (ruolo `delibera`), o quella della sua gestione: il secondo piano di giugno, salvato senza scegliere di nuovo la delibera,
 * conta lo stesso. Solo per le delibere delle materie dei lavori ({@see SuiLavori::MATERIE}: lavori, innovazioni, e le materie della legge che portano
 * opere); solo per chi vede le assemblee.
 */
final class FondoSpecialeDelPiano
{
    /** @return ?array{frase: string, legge: string, chiesti: int, deliberati: int, delibera_id: int, assemblea_id: int} */
    public static function perLaPagina(PianoRate $piano, ?User $utente): ?array
    {
        if (! AccessoAlleAssemblee::vede($utente)) {
            return null;
        }
        $delibera = self::deliberaDeiLavori($piano);
        if ($delibera === null) {
            return null;
        }
        $fondo = SuiLavori::fondoSpeciale($delibera);
        if ($fondo === null || $fondo['pari'] || $fondo['frase'] === null) {
            return null;
        }

        return ['frase' => $fondo['frase'], 'legge' => $fondo['legge'], 'chiesti' => $fondo['chiesti'], 'deliberati' => $fondo['deliberati'],
            'delibera_id' => (int) $delibera->id, 'assemblea_id' => (int) $delibera->assemblea_id];
    }

    /** La delibera di lavori del piano, o della sua gestione. */
    public static function deliberaDeiLavori(PianoRate $piano): ?Delibera
    {
        $candidate = [CollegamentiDelleDelibere::delibera($piano, RuoloCollegamento::DELIBERA->value)];
        $gestione = $piano->gestione;
        if ($gestione !== null) {
            $candidate[] = CollegamentiDelleDelibere::delibera($gestione, RuoloCollegamento::DELIBERA->value);
        }
        foreach (array_filter($candidate) as $d) {
            $d->loadMissing('punto');
            if (in_array($d->punto?->materia, SuiLavori::MATERIE, true)) {
                return $d;
            }
        }

        return null;
    }
}
