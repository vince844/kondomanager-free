<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabella principale del ciclo passivo.
     *
     * stato_approvazione — ciclo di vita:
     *   da_approvare   → fattura inserita, in attesa di revisione
     *   approvata      → ratificata dall'amministratore o dall'assemblea
     *   contestata     → il condominio ha sollevato obiezioni
     *   sforo_motivato → registrata con override budget (urgenza o variante):
     *                    l'amministratore ha fornito motivazione nel Modal,
     *                    ma la ratifica assembleare è ancora pendente.
     *                    Alimenta il widget "Da ratificare" in dashboard.
     *
     * dati_extra (JSON) — chiave 'override_budget' popolata dal Modal di Override:
     * {
     *   "motivazione":               "Intervento urgenza ex Art. 1135 c.c.",
     *   "user_id":                   42,
     *   "timestamp":                 "2026-02-14T10:00:00",
     *   "importo_sforo":             120000,    <- centesimi
     *   "budget_residuo_al_momento": -120000    <- negativo = già in rosso
     * }
     * Questa struttura permette al rendiconto di stampare la motivazione
     * come nota a piè di pagina senza parsing custom aggiuntivo.
     */
    public function up(): void
    {
        Schema::create('fatture_passive', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
            $table->foreignId('esercizio_id')->constrained('esercizi');
            $table->foreignId('fornitore_id')->constrained('fornitori');
            $table->foreignId('conto_corrente_id')->nullable()->constrained('conti_contabili')->nullOnDelete();

            $table->enum('tipo_documento', ['fattura', 'nota_credito']);
            $table->string('numero_documento');
            $table->string('numero_protocollo')->nullable();
            $table->date('data_documento');
            $table->date('data_scadenza');

            // Tutti gli importi in centesimi (BigInt).
            // Evita errori di arrotondamento floating point sui totali di fine anno.
            $table->bigInteger('importo_imponibile');
            $table->bigInteger('importo_iva');
            $table->bigInteger('importo_ritenuta')->default(0);
            $table->bigInteger('totale_documento');
            $table->bigInteger('netto_a_pagare');

            $table->string('stato_pagamento')->default('aperta');

            $table->enum('stato_approvazione', [
                'da_approvare',
                'approvata',
                'contestata',
                'sforo_motivato',
            ])->default('da_approvare');

            $table->string('modalita_pagamento')->nullable();
            $table->string('iban_fornitore')->nullable();

            $table->json('dati_extra')->nullable();

            $table->timestamps();

            // Impedisce doppia registrazione della stessa fattura dello stesso fornitore
            $table->unique(['fornitore_id', 'numero_documento', 'data_documento'], 'unique_ft');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fatture_passive');
    }
};