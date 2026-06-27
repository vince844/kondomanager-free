<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cambia il default di can_comment da false a true e aggiorna
     * le segnalazioni esistenti che non hanno ancora commenti.
     *
     * Nota: le segnalazioni già esistenti vengono aggiornate a true
     * perché il comportamento atteso è "commenti abilitati di default".
     * Chi vuole disabilitarli su una segnalazione specifica può farlo
     * tramite il toggle dedicato nel controller.
     */
    public function up(): void
    {
        set_time_limit(0); // Evita timeout durante la data migration

        // Guard: controlla che la colonna esista (esecuzioni parziali)
        if (! Schema::hasColumn('segnalazioni', 'can_comment')) {
            Log::warning('[Migration] can_comment non trovato in segnalazioni — skip.');
            return;
        }

        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->boolean('can_comment')->default(true)->change();
        });

        $aggiornate = DB::table('segnalazioni')
            ->where('can_comment', false)
            ->update(['can_comment' => true]);

        if ($aggiornate > 0) {
            Log::info("[Migration] can_comment default aggiornato a true su {$aggiornate} segnalazioni esistenti.");
        }
    }

    /**
     * Ripristina il default a false.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('segnalazioni', 'can_comment')) {
            return;
        }

        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->boolean('can_comment')->default(false)->change();
        });
    }
};
