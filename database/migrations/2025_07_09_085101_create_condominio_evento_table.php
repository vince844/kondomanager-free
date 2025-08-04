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
        Schema::create('condominio_evento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventi')->onDelete('cascade');
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['evento_id', 'condominio_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('condominio_evento');
    }
};
