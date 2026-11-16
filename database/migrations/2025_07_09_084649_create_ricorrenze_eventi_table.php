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
        Schema::create('ricorrenze_eventi', function (Blueprint $table) {
            $table->id();
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'yearly']);
            $table->integer('interval')->default(1);
            $table->json('by_day')->nullable(); // ['MO', 'WE']
            $table->integer('by_month_day')->nullable();
            $table->timestamp('until')->nullable(); // null = infinite
            $table->text('rrule')->nullable();
            $table->string('timezone')->default('UTC');
            $table->enum('type', ['custom', 'rrule'])->default('rrule');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ricorrenze_eventi');
    }
};
