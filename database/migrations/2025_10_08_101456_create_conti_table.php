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
        Schema::create('conti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piano_conto_id')->constrained('piani_conti')->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('conti')->onDelete('cascade'); 
            $table->string('nome');
            $table->text('descrizione')->nullable();
            $table->boolean('attivo')->default(true);
            $table->enum('tipo', ['spesa', 'entrata']);
            $table->bigInteger('importo')->default(0); 
            $table->nullableMorphs('destinazione'); 
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conti');
    }
};
