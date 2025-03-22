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
        Schema::create('condomini', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('indirizzo');
            $table->string('email')->nullable()->unique();
            $table->string('codice_fiscale')->nullable()->unique();
            $table->string('comune_catasto')->nullable();
            $table->string('codice_catasto')->nullable();
            $table->string('sezione_catasto')->nullable();
            $table->string('foglio_catasto')->nullable();
            $table->string('particella_catasto')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('condomini');
    }
};
