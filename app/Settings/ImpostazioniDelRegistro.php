<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Settings;

use App\Services\RegistroModifiche\RegistroModifiche;
use Spatie\LaravelSettings\Settings;

/**
 * La base delle impostazioni dello studio (P20 del registro delle modifiche): il salvataggio passa da una transazione (la sua, o quella già aperta),
 * così la riga del registro (scritta dall'ascoltatore di `SavingSettings`) e l'`upsert` del pacchetto vanno o non vanno insieme.
 */
abstract class ImpostazioniDelRegistro extends Settings
{
    public function save(): self
    {
        return RegistroModifiche::nellaTransazione(fn () => parent::save());
    }
}
