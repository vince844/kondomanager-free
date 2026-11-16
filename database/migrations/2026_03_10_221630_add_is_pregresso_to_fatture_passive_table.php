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
     * Esegui le migrazioni.
     */
    public function up(): void
    {
        Schema::table('fatture_passive', function (Blueprint $table) {
            // Aggiungiamo il flag per il debito pregresso
            $table->boolean('is_pregresso')
                  ->default(false)
                  ->after('data_scadenza'); 
        });
    }

    /**
     * Inverti le migrazioni.
     */
    public function down(): void
    {
        Schema::table('fatture_passive', function (Blueprint $table) {
            $table->dropColumn('is_pregresso');
        });
    }
};