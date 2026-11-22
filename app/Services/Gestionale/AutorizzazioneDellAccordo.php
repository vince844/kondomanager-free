<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\AccordoRientro;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Autorizzazione dell'assemblea» di un accordo di rientro: il corpo che stava nel controllore degli accordi (`AccordiRientroController::autorizza`), in un
 * servizio che chiamano la scheda dell'accordo e il seguito delle delibere. Un accordo già autorizzato non si tocca; la data e il verbale si scrivono per la
 * strada di oggi, il collegamento alla delibera (quando c'è) nella stessa transazione.
 */
final class AutorizzazioneDellAccordo
{
    public static function autorizza(AccordoRientro $accordo, string $giorno, ?string $verbale, ?Delibera $delibera, ?User $utente): void
    {
        if ($accordo->autorizzato_assemblea) {
            throw ValidationException::withMessages(['data_autorizzazione' => 'Questo accordo è già autorizzato dall\'assemblea.']);
        }
        DB::transaction(function () use ($accordo, $giorno, $verbale, $delibera, $utente) {
            $accordo->update(['autorizzato_assemblea' => true, 'data_autorizzazione' => $giorno, 'verbale_autorizzazione' => $verbale]);
            if ($delibera !== null && $utente !== null) {
                CollegamentiDelleDelibere::collega($accordo, RuoloCollegamento::AUTORIZZAZIONE->value, $delibera, null, $utente);
            }
        });
    }

    /** Dalla delibera: il giorno della riunione e il numero del verbale di quel giorno. */
    public static function dallaDelibera(AccordoRientro $accordo, Delibera $delibera, User $utente): void
    {
        self::autorizza($accordo, (string) $delibera->deliberata_il?->format('Y-m-d'), DeliberaInParole::numeroDelVerbale($delibera), $delibera, $utente);
    }
}
