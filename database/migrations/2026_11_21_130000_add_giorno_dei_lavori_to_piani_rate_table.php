<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il giorno dei lavori urgenti di un piano rate («Lavori ordinati il 02/03/2027»), su una gestione straordinaria con «Urgenza» (art. 1135 c. 2 c.c.).
 *
 * Il piano d'urgenza non ha una delibera: il giorno in cui i lavori sono stati ordinati decide chi paga, come la delibera per gli altri piani. Sulla scheda
 * delle voci il giorno si scrive anche su ogni voce (`competenze_capitolo`); sulla scheda delle fatture non c'è una voce dove scriverlo, e la fattura
 * senza periodo lo prende dal piano. Una colonna sola per le due schede: chi la legge è `GiornoDellUrgenza`.
 *
 * Vuota per tutti gli altri piani, e per i piani d'urgenza di prima: nessun riempimento.
 *
 * Rieseguibile: una colonna, una guardia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('piani_rate')) {
            return;
        }

        if (! Schema::hasColumn('piani_rate', 'giorno_dei_lavori')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->date('giorno_dei_lavori')->nullable()->after('motivazione_autorizzazione')
                    ->comment('Lavori urgenti: il giorno in cui sono stati ordinati, che decide chi paga. Vuota per gli altri piani.');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('piani_rate') && Schema::hasColumn('piani_rate', 'giorno_dei_lavori')) {
            Schema::table('piani_rate', fn (Blueprint $table) => $table->dropColumn('giorno_dei_lavori'));
        }
    }
};
