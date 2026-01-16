<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
       // Esegui la modifica SOLO se la colonna non esiste ancora
        if (Schema::hasTable('conti') && !Schema::hasColumn('conti', 'conto_contabile_id')) {
            Schema::table('conti', function (Blueprint $table) {
                $table->foreignId('conto_contabile_id')
                    ->nullable()
                    ->after('parent_id')
                    ->constrained('conti_contabili')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Controlliamo se la tabella e la colonna esistono prima di provare a cancellarle
        if (Schema::hasTable('conti') && Schema::hasColumn('conti', 'conto_contabile_id')) {
            Schema::table('conti', function (Blueprint $table) {
                // È fondamentale rimuovere PRIMA il vincolo (foreign key)
                $table->dropForeign(['conto_contabile_id']);
                // E POI la colonna
                $table->dropColumn('conto_contabile_id');
            });
        }
    }
};
