<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\MateriaPunto;
use App\Models\User;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\DelegheDellAmministratore;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use Illuminate\Validation\ValidationException;

/**
 * Catena 5, la delega con tetto (decisione 622 (3)): **la delibera è la delega**, con il tetto, l'ambito e la scadenza. Oggi niente nei conti: la riga lo dice
 * («1 delega in vigore»), e lo stralcio, nella sua beta, la leggerà da {@see DelegheDellAmministratore::valida()}.
 */
final class DellaDelega implements Effetto
{
    public function righe(ContestoDellaDelibera $c): array
    {
        if ($c->materia() !== MateriaPunto::DELEGA || ! $c->approvata()) {
            return [];
        }
        $delega = DelegheDellAmministratore::delega($c->delibera);
        $titolo = $delega === null ? 'Delega all\'amministratore' : 'Delega con tetto: ' . $delega['frase'];

        return [RigaDelSeguito::senzaSpunta($c, 'delega', 'delega', $titolo, 'Nessun effetto nei conti adesso: la delega è la delibera stessa.', null, null,
            true, ['delega' => $delega])];
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        throw ValidationException::withMessages(['riga' => 'La delega non ha niente da applicare: è la delibera stessa.']);
    }
}
