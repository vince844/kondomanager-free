<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documenti') || ! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('documenti', 'moderato_da')) {
            Schema::table('documenti', function (Blueprint $table) {
                $table->unsignedBigInteger('moderato_da')->nullable();
            });
        }

        if (! Schema::hasColumn('documenti', 'moderato_at')) {
            Schema::table('documenti', function (Blueprint $table) {
                $table->timestamp('moderato_at')->nullable();
            });
        }

        if (! $this->foreignKeyEsiste('documenti', 'moderato_da')) {
            Schema::table('documenti', function (Blueprint $table) {
                $table->foreign('moderato_da', 'documenti_moderato_da_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('documenti')) {
            return;
        }

        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('documenti', 'moderato_da')) {
            Schema::table('documenti', fn (Blueprint $table) => $table->dropForeign('documenti_moderato_da_foreign'));
        }

        if (Schema::hasColumn('documenti', 'moderato_da')) {
            $haForeign = ! $mysql && $this->foreignKeyEsiste('documenti', 'moderato_da');
            Schema::table('documenti', function (Blueprint $table) use ($haForeign) {
                if ($haForeign) {
                    $table->dropForeign(['moderato_da']);
                }
                $table->dropColumn('moderato_da');
            });
        }

        if (Schema::hasColumn('documenti', 'moderato_at')) {
            Schema::table('documenti', function (Blueprint $table) {
                $table->dropColumn('moderato_at');
            });
        }
    }

    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return Schema::hasColumn($tabella, $colonna) && $this->sqliteHaForeign($tabella, $colonna);
        }

        return DB::selectOne('
            SELECT COUNT(*) AS n
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
              AND referenced_table_name IS NOT NULL
        ', [$tabella, $colonna])->n > 0;
    }

    private function sqliteHaForeign(string $tabella, string $colonna): bool
    {
        foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $fk) {
            if (($fk->from ?? null) === $colonna) {
                return true;
            }
        }

        return false;
    }
};

