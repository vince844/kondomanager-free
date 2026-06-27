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
        if (!Schema::hasColumn('piani_rate', 'nota_scoperti')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->text('nota_scoperti')->nullable()->after('note');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('piani_rate', 'nota_scoperti')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->dropColumn('nota_scoperti');
            });
        }
    }
};
