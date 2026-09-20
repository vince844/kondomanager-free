<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 2 (1.11.0-beta.31): la competenza di un capitolo ordinario come insieme di tratti.
 *
 * Decisione 20 del progetto (`docs/subentro_e_competenza_temporale.md` §9): due colonne `dal`/`al` sul
 * capitolo non rappresentano il riscaldamento negli esercizi solari — dal 1° gennaio al 15 aprile **e**
 * dal 15 ottobre al 31 dicembre (zona E, DPR 74/2013). Quindi una tabella figlia di `piano_rate_capitoli`
 * (la pivot piano × conto, che ha un `id`), con **N tratti** per capitolo. Nessuna riga = periodo della
 * gestione, poi dell'esercizio (cascata di D3, ramo ordinario). L'unione dei tratti la fa
 * `App\Support\InsiemePeriodi`, che rifiuta le sovrapposizioni.
 *
 * È il posto della competenza dell'ordinario perché è una proprietà **della spesa e dell'anno** — non della
 * tabella millesimale, che può servire spese con periodi diversi (dove la mette Danea: `TTABELLE` con un
 * secondo periodo, vedi §7 del piano esecutivo).
 *
 * Rieseguibile: `create` dentro `hasTable`, FK nella stessa `create` (tabella nuova). Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('competenze_capitolo') || ! Schema::hasTable('piano_rate_capitoli')) {
            return;
        }

        Schema::create('competenze_capitolo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piano_rate_capitolo_id')->constrained('piano_rate_capitoli')->cascadeOnDelete();
            $table->date('dal');
            $table->date('al');
            $table->unsignedTinyInteger('ordine')->default(0)->comment('Posizione del tratto nell\'insieme; i tratti si riordinano comunque per data.');
            $table->timestamps();

            $table->index(['piano_rate_capitolo_id', 'dal'], 'idx_competenze_capitolo_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competenze_capitolo');
    }
};
