<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conti', function (Blueprint $table) {
            $table->foreignId('conto_contabile_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('conti_contabili')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conti', function (Blueprint $table) {
            $table->dropForeign(['conto_contabile_id']);
            $table->dropColumn('conto_contabile_id');
        });
    }
};
