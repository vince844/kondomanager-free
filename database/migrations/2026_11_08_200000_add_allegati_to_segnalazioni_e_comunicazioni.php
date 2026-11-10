<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABELLE = ['segnalazioni', 'comunicazioni'];

    public function up(): void
    {
        foreach (self::TABELLE as $tabella) {
            if (! Schema::hasTable($tabella)) {
                continue;
            }

            if (! Schema::hasColumn($tabella, 'allegati_accesso')) {
                Schema::table($tabella, function (Blueprint $table) {
                    $table->string('allegati_accesso', 20)->default('aperti');
                });
            }

            if (! Schema::hasColumn($tabella, 'allegati_moderati')) {
                Schema::table($tabella, function (Blueprint $table) {
                    $table->boolean('allegati_moderati')->default(true);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABELLE as $tabella) {
            if (! Schema::hasTable($tabella)) {
                continue;
            }

            if (Schema::hasColumn($tabella, 'allegati_accesso')) {
                Schema::table($tabella, function (Blueprint $table) {
                    $table->dropColumn('allegati_accesso');
                });
            }

            if (Schema::hasColumn($tabella, 'allegati_moderati')) {
                Schema::table($tabella, function (Blueprint $table) {
                    $table->dropColumn('allegati_moderati');
                });
            }
        }
    }
};

