<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Se stai usando MySQL/MariaDB, l'alterazione di un ENUM richiede un comando grezzo (raw statement)
        // per essere sicuri che non sovrascriva i vecchi dati.
         if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE piani_rate MODIFY COLUMN metodo_distribuzione ENUM('prima_rata', 'tutte_rate', 'rata_zero') NOT NULL DEFAULT 'prima_rata'");
        }
        
       /*  DB::statement("ALTER TABLE piani_rate MODIFY COLUMN metodo_distribuzione ENUM('prima_rata', 'tutte_rate', 'rata_zero') NOT NULL DEFAULT 'prima_rata'"); */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // In caso di rollback, torniamo alla versione precedente.
        // ATTENZIONE: Se abbiamo già dei record con 'rata_zero', il rollback fallirà a meno che non li modifichiamo prima.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE piani_rate MODIFY COLUMN metodo_distribuzione ENUM('prima_rata', 'tutte_rate') NOT NULL DEFAULT 'prima_rata'");
        }
    }
};