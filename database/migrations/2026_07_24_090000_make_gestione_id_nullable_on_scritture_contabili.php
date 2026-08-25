<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rende `gestione_id` nullable su `scritture_contabili`.
 *
 * Perché: una scrittura di apertura di cassa è un fatto di CONDOMINIO ed ESERCIZIO,
 * non di gestione — la tabella `casse` non ha nemmeno `gestione_id`, e il saldo di
 * apertura di un conto corrente non appartiene né all'ordinaria né alla straordinaria.
 * Agganciarlo d'ufficio alla gestione ordinaria solo per soddisfare la FK inquinerebbe
 * ogni report per-gestione con un movimento che non le compete.
 *
 * Regola risultante: `gestione_id` valorizzato quando il fatto appartiene a una
 * gestione (tutte le scritture esistenti: incassi, pagamenti, giroconti, rettifiche);
 * NULL quando il fatto è di condominio (aperture di cassa).
 *
 * Retrocompatibile: puramente permissiva. Nessuna scrittura esistente viene toccata,
 * nessun dato cambia, e il codice che filtra per gestione continua a funzionare
 * identico sulle scritture già presenti (che hanno tutte la gestione valorizzata).
 *
 * @see docs/fondo_accantonato_e_quadratura_sp.md §10 D7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scritture_contabili', function (Blueprint $table) {
            $table->foreignId('gestione_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Attenzione: il rollback fallisce se esistono aperture con gestione_id NULL.
        // Vanno prima rimosse o riassegnate a una gestione.
        Schema::table('scritture_contabili', function (Blueprint $table) {
            $table->foreignId('gestione_id')->nullable(false)->change();
        });
    }
};
