<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 5 (1.11.0-beta.31): l'indice per leggere la storia di una coppia (unità, ruolo).
 *
 * Con i periodi (D7) la domanda del risolutore diventa «quali righe ha questa coppia (immobile,
 * tipologia), in ordine di decorrenza», e la fa per ogni unità a ogni generazione. Indice **non**
 * unico, di proposito: la storicizzazione ammette più righe per coppia — chi vende e ricompra,
 * l'inquilino che diventa proprietario — e un indice unico è esattamente ciò che la esclude
 * (progetto §4.4, migrazione 5).
 *
 * Rieseguibile: `Schema::hasIndex` per nome. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const INDICE = 'idx_anagrafica_immobile_periodo';

    public function up(): void
    {
        if (! Schema::hasTable('anagrafica_immobile') || Schema::hasIndex('anagrafica_immobile', self::INDICE)) {
            return;
        }

        Schema::table('anagrafica_immobile', function (Blueprint $table) {
            $table->index(['immobile_id', 'tipologia', 'data_inizio'], self::INDICE);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('anagrafica_immobile') && Schema::hasIndex('anagrafica_immobile', self::INDICE)) {
            Schema::table('anagrafica_immobile', fn (Blueprint $table) => $table->dropIndex(self::INDICE));
        }
    }
};
