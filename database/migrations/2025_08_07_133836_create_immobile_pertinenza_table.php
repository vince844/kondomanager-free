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
        Schema::create('immobile_pertinenza', function (Blueprint $table) {
            $table->id();
            $table->foreignId('immobile_id')->constrained('immobili')->onDelete('cascade');
            $table->foreignId('pertinenza_id')->constrained('immobili')->onDelete('cascade');
            $table->decimal('quota_possesso', 5, 2)->default(100.00);
            $table->timestamps();
            $table->unique(['immobile_id', 'pertinenza_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('immobile_pertinenza');
    }
};
