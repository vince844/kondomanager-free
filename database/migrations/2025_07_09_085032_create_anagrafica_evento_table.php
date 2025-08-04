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
        Schema::create('anagrafica_evento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventi')->onDelete('cascade');
            $table->foreignId('anagrafica_id')->constrained('anagrafiche')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['evento_id', 'anagrafica_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anagrafica_evento');
    }
};
