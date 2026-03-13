<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scritture_contabili', function (Blueprint $table) {
            $table->foreignId('scrittura_padre_id')
                  ->nullable()
                  ->constrained('scritture_contabili')
                  ->nullOnDelete()
                  ->after('tipo_movimento');
        });
    }

    public function down(): void
    {
        Schema::table('scritture_contabili', function (Blueprint $table) {
            $table->dropForeign(['scrittura_padre_id']);
            $table->dropColumn('scrittura_padre_id');
        });
    }
};