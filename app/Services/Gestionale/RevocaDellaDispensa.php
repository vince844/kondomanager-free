<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La revoca di una dispensa dei sei mesi (art. 1129 c. 9 c.c.): il corpo che stava nel controllore delle dispense (`DispensaSeiMesiController::revoca`), in
 * un servizio che chiamano la pagina delle dispense e il seguito delle delibere. Una dispensa già revocata non si revoca di nuovo; la revoca non è prima
 * della delibera della dispensa. Il collegamento alla delibera (ruolo `revoca_della_dispensa`) nella stessa transazione.
 */
final class RevocaDellaDispensa
{
    public static function revoca(DispensaSeiMesi $dispensa, string $giorno, ?string $nota, ?Delibera $delibera, ?User $utente): void
    {
        if ($dispensa->revocata()) {
            throw ValidationException::withMessages(['revocata_il' => 'Questa dispensa è già revocata.']);
        }
        if ($dispensa->data_delibera !== null && $giorno < $dispensa->data_delibera->format('Y-m-d')) {
            throw ValidationException::withMessages(['revocata_il' => 'La revoca non può essere prima della delibera della dispensa.']);
        }
        DB::transaction(function () use ($dispensa, $giorno, $nota, $delibera, $utente) {
            $dispensa->update(['revocata_il' => $giorno, 'nota_revoca' => $nota, 'revocata_da' => $utente?->id]);
            if ($delibera !== null && $utente !== null) {
                CollegamentiDelleDelibere::collega($dispensa, RuoloCollegamento::REVOCA_DELLA_DISPENSA->value, $delibera, null, $utente);
            }
        });
    }

    /** Dalla delibera: revocata il giorno della riunione. */
    public static function dallaDelibera(DispensaSeiMesi $dispensa, Delibera $delibera, User $utente): void
    {
        self::revoca($dispensa, (string) $delibera->deliberata_il?->format('Y-m-d'), null, $delibera, $utente);
    }
}
