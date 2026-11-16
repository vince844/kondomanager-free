<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('piano_rate_capitoli', function (Blueprint $table) {
            // 1. L'importo parziale (NULL = Prendi tutto il residuo)
            $table->integer('importo')->nullable()->after('conto_id')
                  ->comment('Importo parziale in centesimi. Se NULL, usa intero residuo.');
            
            // 2. La nota esplicativa (Es. "Quota fissa", "Solo scala A", ecc.)
            $table->string('note')->nullable()->after('importo')
                  ->comment('Motivazione della parzializzazione.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('piano_rate_capitoli', function (Blueprint $table) {
            $table->dropColumn(['importo', 'note']);
        });
    }
};
