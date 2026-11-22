<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\RegistroModifiche;

use LogicException;

/**
 * Una riga del registro delle modifiche non si cambia e non si cancella (decisione 518): nessuno, neanche l'amministratore. Il modello la solleva su un
 * `update` e su un `delete`; le tre scritture ammesse dopo la nascita (sigillo, oscuramento, conservazione) passano da `DB::table` nei loro servizi.
 *
 * È un errore di programmazione, non un caso dell'amministratore: per questo `LogicException`, e nessuna pagina la cattura.
 */
final class RigaDelRegistroIntoccabile extends LogicException
{
    public static function modifica(?int $id): self
    {
        return new self("La riga {$id} del registro delle modifiche non si cambia: si scrive una riga nuova.");
    }

    public static function cancellazione(?int $id): self
    {
        return new self("La riga {$id} del registro delle modifiche non si cancella.");
    }
}
