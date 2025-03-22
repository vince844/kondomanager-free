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
        Schema::create('anagrafiche', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); 
            $table->string('nome');
            $table->string('indirizzo');
            $table->string('email')->nullable()->unique();
            $table->string('email_secondaria')->nullable()->unique();
            $table->string('pec')->nullable()->unique();
            $table->string('codice_fiscale')->nullable()->unique();
            $table->enum('tipologia_documento', ['passport', 'id_card'])->nullable();
            $table->string('numero_documento')->nullable()->unique();
            $table->date('scadenza_documento')->nullable();
            $table->string('luogo_nascita')->nullable();
            $table->date('data_nascita')->nullable();
            $table->string('telefono')->nullable();
            $table->string('cellulare')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anagrafiche');
    }
};
