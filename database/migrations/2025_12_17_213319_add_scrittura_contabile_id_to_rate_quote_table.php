<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_quote', function (Blueprint $table) {
            $table->foreignId('scrittura_contabile_id')
                ->nullable()
                ->after('rata_id')
                ->constrained('scritture_contabili')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rate_quote', function (Blueprint $table) {
            $table->dropForeign(['scrittura_contabile_id']);
            $table->dropColumn('scrittura_contabile_id');
        });
    }
};
