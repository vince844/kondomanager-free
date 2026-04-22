<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE conti_contabili MODIFY COLUMN tipo ENUM(
                'attivo',
                'passivo',
                'costo',
                'ricavo'
            ) NOT NULL");

            DB::statement("ALTER TABLE conti_contabili MODIFY COLUMN categoria ENUM(
                'liquidita',
                'crediti',
                'debiti',
                'fondi',
                'costi',
                'ricavi'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE conti_contabili MODIFY COLUMN tipo ENUM(
                'attivo',
                'passivo'
            ) NOT NULL");

            DB::statement("ALTER TABLE conti_contabili MODIFY COLUMN categoria ENUM(
                'liquidita',
                'crediti',
                'debiti',
                'fondi'
            ) NOT NULL");
        }
    }
};