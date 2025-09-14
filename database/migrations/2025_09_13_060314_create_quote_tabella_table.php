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
        Schema::create('quote_tabella', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tabella_id')
                ->constrained('tabelle')
                ->onDelete('cascade');
            $table->foreignId('immobile_id')
                ->constrained('immobili')
                ->onDelete('cascade');
            // valore generico per millesimi, persone, kW, mcubi, ecc.
            $table->decimal('valore', 12, 5)->nullable();
            // posso aggiungere coefficienti aggiuntivi per esempio il piano nel caso della tabella ascensore etc
            $table->json('coefficienti')->nullable();
            $table->boolean('escluso')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tabella_id', 'immobile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_tabella');
    }
};
