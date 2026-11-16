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
        Schema::create('conto_tabella_millesimale', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conto_id')->constrained('conti')->onDelete('cascade');
            $table->foreignId('tabella_id')->constrained('tabelle')->onDelete('cascade');
            $table->decimal('coefficiente', 5, 2)->default(100.00);
            $table->timestamps();
            $table->unique(['conto_id', 'tabella_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conto_tabella_millesimale');
    }
};
