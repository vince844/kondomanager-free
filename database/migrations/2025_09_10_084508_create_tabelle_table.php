<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tabelle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->foreignId('palazzina_id')->nullable()->constrained('palazzine')->onDelete('set null');
            $table->foreignId('scala_id')->nullable()->constrained('scale')->onDelete('set null');
            $table->string('nome');
            $table->enum('tipo', ['standard','ascensore','scale','riscaldamento','acqua','lastrico','speciale','altro'])->default('standard');
            $table->enum('quota', ['millesimi','persone','kwatt','mtcubi','quote'])->default('millesimi');
            $table->unsignedTinyInteger('numero_decimali')->default(2);
            $table->json('regole_calcolo')->nullable();
            $table->text('descrizione')->nullable();
            $table->text('note')->nullable();
            $table->boolean('attiva')->default(true);
            $table->date('data_inizio')->nullable();
            $table->date('data_fine')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tabelle');
    }
};
