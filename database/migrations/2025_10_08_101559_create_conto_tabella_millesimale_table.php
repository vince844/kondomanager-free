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
        Schema::create('conto_tabella_millesimale', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conto_id')->constrained('conti')->onDelete('cascade');
            $table->foreignId('tabella_id')->constrained('tabelle')->onDelete('cascade');
            $table->decimal('coefficiente', 5, 2)->default(100.00);
            $table->timestamps();
            $table->unique(['conto_id', 'tabella_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conto_tabella_millesimale');
    }
};
