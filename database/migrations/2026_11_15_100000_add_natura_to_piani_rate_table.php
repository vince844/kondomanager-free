<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La natura di un piano rate: vuota per i piani di sempre, «addebiti» per il piano degli addebiti a singole persone di una gestione in un esercizio.
 *
 * Il piano degli addebiti tiene le somme chieste a una persona sola, fuori dal riparto (una quota che nessuno ha avuto, una spesa di una sola unità):
 * ogni addebito è una sua rata, con una quota sola. Le rate si leggono, si incassano e si stampano come le altre; ma il piano non si genera, non si
 * ricalcola, non si approva e non entra nel deliberato, nelle quote del conguaglio né nei passaggi di proprietà. Lo dice questa colonna, letta da chi
 * deve lasciarlo fuori.
 *
 * Una colonna sua e non un valore nuovo di `piani_rate.tipo`: quella su MySQL è un `enum('ordinario','straordinario')`, e il codice legge «non
 * straordinario» come «ordinario».
 *
 * Nessun riempimento: i piani di oggi restano con la natura vuota, che è il loro vero.
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

        if (! Schema::hasColumn('piani_rate', 'natura')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->string('natura', 20)->nullable()->after('tipo')
                    ->comment('Vuota per i piani del riparto; «addebiti» per il piano degli addebiti a singole persone.');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('piani_rate') && Schema::hasColumn('piani_rate', 'natura')) {
            Schema::table('piani_rate', fn (Blueprint $table) => $table->dropColumn('natura'));
        }
    }
};
