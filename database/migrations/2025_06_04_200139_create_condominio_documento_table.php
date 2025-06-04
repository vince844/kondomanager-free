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
        Schema::create('condominio_documento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documento_id')->constrained('documenti')->onDelete('cascade');
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['documento_id', 'condominio_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('condominio_documento');
    }
};
