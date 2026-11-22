<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Services\RegistroModifiche\SigilloDelRegistro;
use Illuminate\Console\Command;

/**
 * Sigilla le righe del registro delle modifiche rimaste senza posto nella catena (§3 del progetto): di solito le sigilla il commit che le ha scritte; una
 * riga di un processo interrotto fra il commit e il sigillo la prende questo comando, ogni cinque minuti.
 */
class SigillaRegistroCommand extends Command
{
    protected $signature = 'kondomanager:sigilla-registro';

    protected $description = 'Sigilla le righe del registro delle modifiche rimaste senza posto nella catena';

    public function handle(): int
    {
        $fatte = SigilloDelRegistro::sigilla();
        $this->info(match (true) {
            $fatte === 0 => 'Nessuna riga da sigillare.',
            $fatte === 1 => 'Sigillata 1 riga.',
            default => "Sigillate {$fatte} righe.",
        });

        return self::SUCCESS;
    }
}
