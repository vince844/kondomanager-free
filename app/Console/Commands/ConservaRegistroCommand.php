<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Services\RegistroModifiche\ConservazioneDelRegistro;
use Illuminate\Console\Command;

/**
 * Toglie le righe del registro delle modifiche più vecchie della durata scelta (§11 del progetto, P13): un prefisso della catena, mai un buco; salta i
 * condomini con la cancellazione sospesa (decisione 550); scrive la riga «conservazione» firmata «Il programma». Pianificato una volta al giorno.
 */
class ConservaRegistroCommand extends Command
{
    protected $signature = 'kondomanager:conserva-registro';

    protected $description = 'Toglie le righe del registro delle modifiche più vecchie della durata scelta';

    public function handle(): int
    {
        $riga = ConservazioneDelRegistro::conserva();
        $this->info($riga === null ? 'Nessuna riga più vecchia di ' . ConservazioneDelRegistro::testoDegliAnni(ConservazioneDelRegistro::anni()) . '.'
            : (string) $riga->riepilogo);

        return self::SUCCESS;
    }
}
