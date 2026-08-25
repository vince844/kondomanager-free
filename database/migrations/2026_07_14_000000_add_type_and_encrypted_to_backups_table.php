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
        if (! Schema::hasColumn('backups', 'type')) {
            Schema::table('backups', function (Blueprint $table) {
                // 'full' = database + documenti + .env; 'db_only' = solo dump database
                $table->string('type', 20)->default('full')->after('status');
            });
        }

        if (! Schema::hasColumn('backups', 'encrypted')) {
            Schema::table('backups', function (Blueprint $table) {
                $table->boolean('encrypted')->default(false)->after('type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            if (Schema::hasColumn('backups', 'type')) {
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('backups', 'encrypted')) {
                $table->dropColumn('encrypted');
            }
        });
    }
};
