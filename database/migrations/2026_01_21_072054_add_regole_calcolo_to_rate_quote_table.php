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
        Schema::table('rate_quote', function (Blueprint $table) {
            // Aggiunge ulteriori regole di calcolo e log storico per la quota
            $table->json('regole_calcolo')->nullable()->after('importo_pagato');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rate_quote', function (Blueprint $table) {
            $table->dropColumn('regole_calcolo');
        });
    }
};
