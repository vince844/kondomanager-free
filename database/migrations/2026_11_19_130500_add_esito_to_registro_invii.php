<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §0.4 e §1.2 del progetto della tappa 2): l'esito di un invio e il giorno che conta. Gli stati di oggi dicono che il server ha
 * accettato il messaggio o che la lettera è partita, non che è arrivata. `esito` (consegnata, in giacenza, rifiutata, non consegnata), `esito_il` (la
 * consegna della PEC con l'ora, l'avviso di giacenza, la consegna a mano) e `ricevuta_il`, **il giorno che conta** per i cinque giorni: il primo fra la
 * consegna e l'avviso di giacenza (Cass. 23396/2017); per «rifiutata» resta vuoto. Vale per la convocazione; le righe di oggi restano con l'esito vuoto.
 *
 * Rieseguibile: una guardia per colonna. Nessuna chiave esterna; l'indice del documento c'è già. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registro_invii')) {
            return;
        }
        if (! Schema::hasColumn('registro_invii', 'esito')) {
            Schema::table('registro_invii', function (Blueprint $t) {
                $t->string('esito', 20)->nullable()->comment('consegnata, in_giacenza, rifiutata o non_consegnata; vuoto per le righe che non lo chiedono.');
            });
        }
        if (! Schema::hasColumn('registro_invii', 'esito_il')) {
            Schema::table('registro_invii', function (Blueprint $t) {
                $t->dateTime('esito_il')->nullable()->comment('Quando: la consegna della PEC con l\'ora, l\'avviso di giacenza, la consegna a mano.');
            });
        }
        if (! Schema::hasColumn('registro_invii', 'ricevuta_il')) {
            Schema::table('registro_invii', function (Blueprint $t) {
                $t->date('ricevuta_il')->nullable()->comment('Il giorno che conta per i termini: il primo fra la consegna e l\'avviso di giacenza.');
            });
        }
    }

    public function down(): void
    {
        foreach (['ricevuta_il', 'esito_il', 'esito'] as $colonna) {
            if (Schema::hasTable('registro_invii') && Schema::hasColumn('registro_invii', $colonna)) {
                Schema::table('registro_invii', fn (Blueprint $t) => $t->dropColumn($colonna));
            }
        }
    }
};
