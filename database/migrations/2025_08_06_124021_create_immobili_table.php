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
        Schema::create('immobili', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->foreignId('palazzina_id')->nullable()->constrained('palazzine')->onDelete('set null');
            $table->foreignId('scala_id')->nullable()->constrained('scale')->onDelete('set null');
            $table->foreignId('tipologia_id')->nullable()->constrained('tipologie_immobili')->onDelete('set null');
            $table->string('nome');
            $table->string('descrizione');
            $table->string('interno');
            $table->string('piano')->nullable();
            $table->decimal('superficie', 8, 2)->nullable(); 
            $table->integer('numero_vani')->nullable(); 
            $table->string('codice_immobile')->unique(); 
            $table->string('comune_catasto')->nullable();
            $table->string('codice_catasto')->nullable();
            $table->string('sezione_catasto')->nullable();
            $table->string('foglio_catasto')->nullable();
            $table->string('particella_catasto')->nullable();
            $table->string('subalterno_catasto')->nullable();
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
        Schema::dropIfExists('immobili');
    }
};
