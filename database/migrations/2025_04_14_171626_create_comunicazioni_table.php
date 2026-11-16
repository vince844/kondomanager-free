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
        Schema::create('comunicazioni', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('description');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->enum('priority', ['bassa', 'media', 'alta', 'urgente'])->default('bassa');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_private')->default(false);
            $table->boolean('is_published')->default(true);
            $table->boolean('is_approved')->default(true);
            $table->boolean('can_comment')->default(true);
            $table->string('slug')->unique();
            $table->string('reference')->unique()->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comunicazioni');
    }
};
