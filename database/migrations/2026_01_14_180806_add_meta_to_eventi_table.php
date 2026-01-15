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
        Schema::table('eventi', function (Blueprint $table) {
            // Aggiungiamo 'meta' per salvare contesti (es. piano_rate_id) e configurazioni JSON
            $table->json('meta')->nullable()->after('is_approved'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eventi', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
