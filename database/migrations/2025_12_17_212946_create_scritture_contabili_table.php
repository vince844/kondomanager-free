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
        Schema::create('scritture_contabili', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
            $table->foreignId('gestione_id')->constrained('gestioni')->cascadeOnDelete();
            $table->foreignId('esercizio_id')->constrained('esercizi')->cascadeOnDelete();
            $table->date('data_registrazione');
            $table->date('data_competenza');
            $table->string('numero_protocollo', 20);
            $table->string('causale');
            $table->text('descrizione')->nullable();
            $table->enum('tipo_movimento', ['incasso_rata','pagamento_fornitore','giroconto','rettifica','apertura','chiusura','emissione_rata']);
            $table->enum('stato', ['bozza','registrata','riconciliata','annullata'])->default('bozza');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('registrata_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registrata_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['condominio_id','numero_protocollo']);
            $table->index(['gestione_id','data_registrazione']);
            $table->index(['esercizio_id','stato']);
            $table->index('tipo_movimento');
            $table->index('stato');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scritture_contabili');
    }
};
