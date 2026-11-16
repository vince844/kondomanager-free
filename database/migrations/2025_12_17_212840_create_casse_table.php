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
        Schema::create('casse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->string('nome');
            $table->string('descrizione')->nullable();
            $table->enum('tipo', ['contanti','banca','fondo','virtuale'])->default('banca');
            $table->foreignId('conto_contabile_id')->constrained('conti_contabili')->restrictOnDelete()->unique();
            $table->bigInteger('saldo_iniziale')->default(0)->comment('Saldo apertura in centesimi');
            $table->boolean('attiva')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['condominio_id', 'nome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('casse');
    }
};
