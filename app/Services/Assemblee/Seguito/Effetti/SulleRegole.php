<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\User;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use Illuminate\Validation\ValidationException;

/**
 * Catena 8, le regole del recupero: approvate, **una cosa da fare** «Scrivi la regola», che apre le regole con la delibera scelta. La regola nasce nuova
 * (una regola non si riscrive), con la fonte «delibera»; salvata, la cosa è fatta (R5, ruolo `fonte`). Gli interessi e le spese che seguono la leggono.
 */
final class SulleRegole implements Effetto
{
    public function righe(ContestoDellaDelibera $c): array
    {
        if ($c->materia() !== MateriaPunto::REGOLE || ! $c->approvata()) {
            return [];
        }

        return [RigaDelSeguito::cosa($c, TipoCosaDaFare::SCRIVI_REGOLA, 'regole', 'Criteri per gli interessi e le spese di recupero dei crediti',
            'Approvata, ma resta da fare: una regola non si riscrive, ne nasce una nuova con la fonte «delibera del ' . $c->giorno()
            . ($c->punto !== null ? ', punto ' . $c->punto->numero : '') . '». Gli addebiti che seguono leggono quella.', 'Scrivi la regola')];
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        throw ValidationException::withMessages(['riga' => 'La regola si scrive nella sua pagina.']);
    }
}
