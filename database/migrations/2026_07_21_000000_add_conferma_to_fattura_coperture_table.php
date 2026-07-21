<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beta.19 — Giroconti: la conferma delle coperture diventa un fatto contabile.
 *
 * Una copertura fondo nasce 'pianificata' alla registrazione della fattura in
 * sforo; finora nessun flusso la portava mai a 'confermata' (lo stato esisteva
 * nell'enum dal primo giorno, in attesa di questa feature). Da questa versione
 * la conferma avviene registrando un giroconto fondo → banca: queste due colonne
 * collegano la copertura alla scrittura che l'ha resa reale.
 *
 * Lo storno del giroconto riporta la copertura a 'pianificata' e azzera entrambe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL non ha DDL transazionale: se una migrazione precedente è morta a
        // metà, ripartiamo da uno stato pulito (pattern della migrazione che ha
        // creato fattura_coperture).
        $this->cleanupPartialMigration();

        Schema::table('fattura_coperture', function (Blueprint $table) {
            $table->foreignId('scrittura_giroconto_id')
                ->nullable()
                ->after('fondo_id')
                ->constrained('scritture_contabili')
                ->nullOnDelete()
                ->comment('Scrittura di giroconto che ha confermato la copertura (beta.19)');

            $table->timestamp('confermata_at')
                ->nullable()
                ->after('scrittura_giroconto_id');
        });
    }

    public function down(): void
    {
        Schema::table('fattura_coperture', function (Blueprint $table) {
            if (Schema::hasColumn('fattura_coperture', 'scrittura_giroconto_id')) {
                $table->dropConstrainedForeignId('scrittura_giroconto_id');
            }
            if (Schema::hasColumn('fattura_coperture', 'confermata_at')) {
                $table->dropColumn('confermata_at');
            }
        });
    }

    private function cleanupPartialMigration(): void
    {
        Schema::table('fattura_coperture', function (Blueprint $table) {
            if (Schema::hasColumn('fattura_coperture', 'scrittura_giroconto_id')) {
                $table->dropConstrainedForeignId('scrittura_giroconto_id');
            }
            if (Schema::hasColumn('fattura_coperture', 'confermata_at')) {
                $table->dropColumn('confermata_at');
            }
        });
    }
};
