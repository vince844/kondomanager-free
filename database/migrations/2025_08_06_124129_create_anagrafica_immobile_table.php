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
        Schema::create('anagrafica_immobile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('immobile_id')->constrained('immobili')->onDelete('cascade');
            $table->foreignId('anagrafica_id')->constrained('anagrafiche')->onDelete('cascade');
            $table->enum('tipologia', ['proprietario', 'usufruttuario', 'inquilino']);
            $table->decimal('quota', 5, 2)->default(100.00);
            $table->json('tipologie_spese')->nullable();
            $table->date('data_inizio');
            $table->date('data_fine')->nullable();
            $table->boolean('attivo')->default(true); 
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anagrafica_immobile');
    }
};
