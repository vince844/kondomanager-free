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
        Schema::table('documenti', function (Blueprint $table) {
            $table->unsignedBigInteger('documentable_id')->nullable();
            $table->string('documentable_type')->nullable();
            $table->index(['documentable_id', 'documentable_type'], 'documentable_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documenti', function (Blueprint $table) {
            $table->dropIndex('documentable_index');
            $table->dropColumn(['documentable_id', 'documentable_type']);
        });
    }
};
