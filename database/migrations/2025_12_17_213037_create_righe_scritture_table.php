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
        Schema::create('righe_scritture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrittura_id')->constrained('scritture_contabili')->cascadeOnDelete();
            $table->foreignId('conto_contabile_id')->constrained('conti_contabili')->cascadeOnDelete();
            $table->foreignId('cassa_id')->nullable()->constrained('casse')->nullOnDelete();
            $table->foreignId('voce_spesa_id')->nullable()->constrained('conti')->nullOnDelete();
            $table->enum('tipo_riga', ['dare','avere']);
            $table->bigInteger('importo'); // centesimi
            $table->foreignId('immobile_id')->nullable()->constrained('immobili')->nullOnDelete();
            $table->foreignId('anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
            $table->foreignId('rata_id')->nullable()->constrained('rate')->nullOnDelete();
            $table->nullableMorphs('riferimento');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['scrittura_id','tipo_riga']);
            $table->index('conto_contabile_id');
            $table->index('immobile_id');
            $table->index('anagrafica_id');
            $table->index('rata_id');
            $table->index('voce_spesa_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('righe_scritture');
    }
};
