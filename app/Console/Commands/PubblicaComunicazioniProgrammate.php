<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Support\Comunicazioni\PubblicazioneProgrammata;
use Illuminate\Console\Command;

class PubblicaComunicazioniProgrammate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'comunicazioni:pubblica-programmate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pubblica le comunicazioni con data programmata scaduta';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $uscite = PubblicazioneProgrammata::pubblicaScadute();

        $this->info("Pubblicate {$uscite} comunicazioni.");

        return 0;
    }
}
