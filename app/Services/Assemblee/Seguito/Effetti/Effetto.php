<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Models\User;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;

/**
 * Una catena del seguito (§3 del progetto della tappa): le righe che propone per una delibera, e l'effetto di una riga `applica` sulla sua scheda. **Ogni
 * scrittura passa dal servizio che il modulo usa già** (R3): un effetto non scrive le tabelle dei moduli da sé, e i collegamenti li scrive solo
 * `CollegamentiDelleDelibere`. Un effetto rifà i controlli del suo modulo: una riga che non si applica lancia l'errore con la frase, e le altre vanno avanti.
 */
interface Effetto
{
    /** Le righe di questa catena per la delibera (nessuna se la catena non la riguarda). @return list<array<string, mixed>> */
    public function righe(ContestoDellaDelibera $c): array;

    /** Applica una riga `applica` di questa catena; rende la frase dell'esito. @param array<string, mixed> $riga */
    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string;
}
