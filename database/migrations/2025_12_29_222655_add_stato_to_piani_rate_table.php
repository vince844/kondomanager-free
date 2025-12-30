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
        Schema::table('piani_rate', function (Blueprint $table) {
            // Default 'bozza'. L'altro valore sarà 'approvato'
            $table->string('stato')->default('bozza')->after('note'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('piani_rate', function (Blueprint $table) {
            $table->dropColumn('stato');
        });
    }
};
