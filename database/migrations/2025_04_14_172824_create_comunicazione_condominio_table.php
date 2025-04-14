<?php

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
        Schema::create('comunicazione_condominio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comunicazione_id')->constrained('comunicazioni')->onDelete('cascade');
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->unique(['comunicazione_id', 'condominio_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comunicazione_condominio');
    }
};
