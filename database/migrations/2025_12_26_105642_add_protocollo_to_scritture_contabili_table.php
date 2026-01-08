<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scritture_contabili') && !Schema::hasColumn('scritture_contabili', 'numero_protocollo')) {
            Schema::table('scritture_contabili', function (Blueprint $table) {
                $table->string('numero_protocollo', 25)->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('scritture_contabili', 'numero_protocollo')) {
            Schema::table('scritture_contabili', function (Blueprint $table) {
                $table->dropColumn('numero_protocollo');
            });
        }
    }
};