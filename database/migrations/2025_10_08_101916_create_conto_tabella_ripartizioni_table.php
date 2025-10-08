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
        Schema::create('conto_tabella_ripartizioni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conto_tabella_millesimale_id')->constrained('conto_tabella_millesimale')->onDelete('cascade');
            $table->enum('soggetto', ['proprietario', 'inquilino', 'usufruttuario'])->default('proprietario');
            $table->unsignedDecimal('percentuale', 5, 2)->default(100.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conto_tabella_ripartizioni');
    }
};
