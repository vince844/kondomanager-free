<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Services\Assemblee\Convocazione\PromemoriaDellaConvocazione;
use Illuminate\Console\Command;

/**
 * Il promemoria per email delle convocazioni da spedire ({@see PromemoriaDellaConvocazione}): il pianificatore lo lancia ogni mattina alle 8:15 di Roma
 * (`routes/console.php`). Si può lanciare a mano quante volte si vuole: manda solo ciò che è dovuto e non è ancora partito.
 */
class PromemoriaDelleConvocazioniCommand extends Command
{
    protected $signature = 'assemblee:promemoria-convocazioni';

    protected $description = 'Manda per email il promemoria delle convocazioni da spedire a chi ha preparato l\'assemblea';

    public function handle(PromemoriaDellaConvocazione $promemoria): int
    {
        $righe = $promemoria->manda();
        foreach ($righe as $riga) {
            $this->line($riga);
        }
        if ($righe === []) {
            $this->line('Nessun promemoria da mandare.');
        }

        return self::SUCCESS;
    }
}
