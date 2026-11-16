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
        Schema::create('anagrafica_comunicazione', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anagrafica_id')->constrained('anagrafiche')->onDelete('cascade');
            $table->foreignId('comunicazione_id')->constrained('comunicazioni')->onDelete('cascade');
            $table->unique(['anagrafica_id', 'comunicazione_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anagrafica_comunicazione');
    }
};
