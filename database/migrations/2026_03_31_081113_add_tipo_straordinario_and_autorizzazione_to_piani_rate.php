<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Aggiorniamo la tabella dei Piani Rate
        Schema::table('piani_rate', function (Blueprint $table) {
            $table->enum('tipo', ['ordinario', 'straordinario'])
                  ->default('ordinario')
                  ->after('stato')
                  ->comment('ordinario = legge dal preventivo (capitoli), straordinario = legge da fatture');

            $table->enum('tipo_autorizzazione', ['delibera', 'urgenza'])
                  ->nullable()
                  ->after('tipo')
                  ->comment('Obbligatorio per piani straordinari. Scudo legale Art. 1135 c.c.');

            $table->text('motivazione_autorizzazione')
                  ->nullable()
                  ->after('tipo_autorizzazione')
                  ->comment('Audit trail: estremi delibera o motivazione urgenza');
        });

        // 2. Creiamo la Pivot Finanziaria (Il Carrello delle fatture)
        Schema::create('piano_rate_fatture', function (Blueprint $table) {
            $table->id();

            $table->foreignId('piano_rate_id')
                  ->constrained('piani_rate')
                  ->cascadeOnDelete();

            $table->foreignId('fattura_passiva_id')
                  ->constrained('fatture_passive')
                  ->cascadeOnDelete();

            // Il cuore finanziario: quanto di questa fattura sto chiedendo con questo piano
            $table->bigInteger('importo_collegato')
                  ->default(0)
                  ->comment('Quota della fattura finanziata da questo specifico piano (in centesimi)');

            // Override esplicito in fase di riscossione (utile per vecchie pendenze)
            $table->foreignId('tabella_millesimale_id')
                  ->nullable()
                  ->constrained('tabelle') // Corretto: punta alla tabella "tabelle"
                  ->nullOnDelete()
                  ->comment('Override manuale della tabella millesimale in fase di riscossione');

            $table->integer('anno_competenza')
                  ->nullable()
                  ->comment('Anno di riferimento per il finanziamento di questa quota');

            $table->timestamps();

            // Indici per performance e integrità
            $table->unique(['piano_rate_id', 'fattura_passiva_id'], 'uq_piano_fattura');
            $table->index('piano_rate_id');
            $table->index('fattura_passiva_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piano_rate_fatture');

        Schema::table('piani_rate', function (Blueprint $table) {
            $table->dropColumn([
                'tipo',
                'tipo_autorizzazione',
                'motivazione_autorizzazione'
            ]);
        });
    }
};