<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il "già versato" (beta.26) è puro dato di riparto: registrarlo non crea
 * alcuna scrittura contabile, quindi non dice se quel denaro esiste davvero da
 * qualche parte. Verificato con un test reale (beta.27): dopo aver registrato
 * un già-versato, la liquidità reale nelle casse del condominio resta
 * invariata — lo Stato Patrimoniale "quadra" formalmente (nessun valore senza
 * contropartita) ma non sa se quei soldi sono ancora fermi in un fondo o sono
 * già stati spesi.
 *
 * Questa colonna registra la RISPOSTA a quella domanda, chiesta esplicitamente
 * all'amministratore (mai dedotta — stesso principio di D2/D4/D8):
 *
 *   'registrata_in_cassa' — i soldi sono ancora fermi: portati a giornale come
 *      accantonamento su una cassa/fondo esistente (RegistraContributoInCassaAction).
 *      `cassa_id` indica dove.
 *   'gia_speso_acconto'   — i soldi sono già usciti come acconto al fornitore,
 *      prima di Kondomanager. Documentato con un task nell'inbox (audit trail),
 *      nessuna scrittura di cassa (non c'è liquidità da registrare).
 *   NULL — non ancora dichiarato (dati storici, o "già versato" registrato
 *      prima di questa correzione).
 *
 * Nessun backfill: non possiamo indovinare la risposta per i dati esistenti,
 * quindi restano NULL — la UI richiederà la dichiarazione al prossimo
 * salvataggio della voce, non retroattivamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL non ha DDL transazionale: le due colonne vengono aggiunte con
        // ALTER separate, quindi un'interruzione a metà ne lascia una sola,
        // senza che la migrazione risulti registrata. Si riparte da uno stato
        // pulito (stesso pattern di add_conferma_to_fattura_coperture).
        $this->cleanupPartialMigration();

        Schema::table('contributi_versati', function (Blueprint $table) {
            $table->string('liquidita_stato', 30)->nullable()->after('origine');
            $table->foreignId('cassa_id')->nullable()->after('liquidita_stato')
                ->constrained('casse')->nullOnDelete();
        });
    }

    private function cleanupPartialMigration(): void
    {
        Schema::table('contributi_versati', function (Blueprint $table) {
            if (Schema::hasColumn('contributi_versati', 'cassa_id')) {
                $table->dropConstrainedForeignId('cassa_id');
            }
            if (Schema::hasColumn('contributi_versati', 'liquidita_stato')) {
                $table->dropColumn('liquidita_stato');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contributi_versati', function (Blueprint $table) {
            $table->dropForeign(['cassa_id']);
            $table->dropColumn(['liquidita_stato', 'cassa_id']);
        });
    }
};
