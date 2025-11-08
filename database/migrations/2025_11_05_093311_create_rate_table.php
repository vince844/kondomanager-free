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
        Schema::create('rate', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piano_rate_id')->constrained('piani_rate')->onDelete('cascade');
            $table->foreignId('conto_id')->nullable()->constrained('conti')->onDelete('set null');
            $table->integer('numero_rata');
            $table->date('data_scadenza');
            $table->date('data_emissione')->nullable();
            $table->text('descrizione')->nullable();
            $table->bigInteger('importo_totale')->default(0); // in centesimi
            $table->enum('stato', ['bozza', 'emessa', 'chiusa'])->default('bozza');
            $table->timestamps();
            $table->unique(['piano_rate_id', 'numero_rata']);
            $table->index('data_scadenza');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate');
    }
};
