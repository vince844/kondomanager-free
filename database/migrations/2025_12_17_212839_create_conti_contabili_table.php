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
        Schema::create('conti_contabili', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
            $table->string('codice', 20);
            $table->string('nome');
            $table->text('descrizione')->nullable();
            $table->enum('tipo', ['attivo', 'passivo']);
            $table->enum('categoria', ['liquidita','crediti','debiti','fondi']);
            $table->string('ruolo')->nullable()->index();
            $table->foreignId('parent_id')->nullable()->constrained('conti_contabili')->cascadeOnDelete();
            $table->integer('livello')->default(1);
            $table->boolean('di_sistema')->default(false);
            $table->boolean('attivo')->default(true);
            $table->integer('ordine')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['condominio_id', 'codice']);
            $table->index(['condominio_id','tipo']);
            $table->index(['condominio_id','categoria']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conti_contabili');
    }
};
