<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piano_rate_capitoli', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piano_rate_id')->constrained('piani_rate')->onDelete('cascade');
            // Colleghiamo ai conti. Nota: Logicamente saranno solo conti "Padre" (Capitoli)
            $table->foreignId('conto_id')->constrained('conti')->onDelete('cascade');
            $table->timestamps();
            // Evitiamo duplicati: lo stesso capitolo non può essere aggiunto 2 volte allo stesso piano
            $table->unique(['piano_rate_id', 'conto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piano_rate_capitoli');
    }
};