<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLONNA = 'ritenuta_decisa_il';

    /**
     * Distinguere «no» da «non gliel'ha mai chiesto nessuno» (Coda 116).
     *
     * ## Il difetto che chiude
     *
     * `fornitori.soggetto_ritenuta` è `tinyint(1) NOT NULL default 0`: un fornitore su cui
     * nessuno si è mai pronunciato è **indistinguibile** da uno per cui la risposta è
     * davvero no. Misurato il 04/09/2026 in sviluppo: 9 fornitori su 13 stanno in quello
     * stato indistinto.
     *
     * Il caso che costa è documentato sui file veri: il decimo XML del collaudo è di un
     * geometra — cedente persona fisica, cassa previdenziale TC03 — e **non ha nessun
     * blocco `<DatiRitenuta>`**. La ritenuta del 20% è dovuta lo stesso, perché l'obbligo è
     * del condominio come sostituto d'imposta e non del fornitore che lo dichiara in
     * fattura. Oggi quel documento si registra a netto pieno, in silenzio: il condominio
     * paga tutto al fornitore e all'Erario non versa niente, restandone responsabile.
     * **Sei degli undici file veri non hanno quel blocco.**
     *
     * ⚠️ **È l'unico buco dell'importazione XML che non si chiude leggendo meglio il file.**
     * Nessun campo del documento può rispondere: la domanda va fatta a chi amministra, una
     * volta sola, e la risposta va ricordata — anche quando è no. Senza questa colonna un
     * «no» tornerebbe indistinguibile da un silenzio, e l'avviso si ripresenterebbe alla
     * fattura successiva: un avviso che non si può chiudere è un avviso che si impara a
     * saltare.
     *
     * ## Perché una data e non un booleano
     *
     * Segue `forfetario_dichiarato_il` e `provvigioni_dichiarazione_il`, che sono la stessa
     * cosa per gli altri due regimi: la posizione fiscale di un fornitore è un fatto con un
     * momento, e saperlo serve quando qualcuno chiede perché una fattura di marzo è stata
     * trattenuta e una di gennaio no.
     */
    public function up(): void
    {
        $this->ripuliParziale();

        Schema::table('fornitori', function (Blueprint $table) {
            $table->timestamp(self::COLONNA)->nullable()->after('provvigioni_dichiarazione_il');
        });

        $this->backfill();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fornitori', self::COLONNA)) {
            return;
        }

        Schema::table('fornitori', function (Blueprint $table) {
            $table->dropColumn(self::COLONNA);
        });
    }

    /**
     * Chi ha già una posizione dichiarata non se la sente chiedere di nuovo.
     *
     * ⚠️ **La data del backfill è `updated_at`, e non è il momento della decisione: è
     * l'ultima volta che qualcuno ha toccato quella scheda.** Il momento vero non lo sa
     * nessuno, e inventarlo sarebbe la stessa cosa che questa beta sta togliendo altrove.
     * `updated_at` è un **limite superiore** onesto — la decisione è stata presa allora o
     * prima — ed è l'unico dato che il database possiede davvero.
     *
     * Restano `null`, cioè da chiedere, solo i fornitori su cui nessuno si è mai
     * pronunciato: `soggetto_ritenuta` falso, nessun regime, nessun forfetario. Che è
     * esattamente la popolazione per cui la Coda 116 è stata aperta.
     */
    private function backfill(): void
    {
        $decisi = DB::table('fornitori')
            ->where(function ($q) {
                $q->where('soggetto_ritenuta', true)
                    ->orWhereNotNull('tipo_ritenuta')
                    ->orWhere('regime_forfetario', true);
            })
            ->whereNull(self::COLONNA)
            ->update([self::COLONNA => DB::raw('`updated_at`')]);

        Log::info('Coda 116: posizione fiscale marcata come già decisa', [
            'fornitori' => $decisi,
        ]);
    }

    /**
     * Una migrazione lasciata a metà non deve impedire il secondo tentativo.
     *
     * Su MySQL il DDL non è transazionale: se il processo muore fra `Schema::table()` e il
     * backfill, la colonna resta e la riesecuzione fallirebbe su «column already exists».
     * Qui la colonna si toglie e si rifà da capo — è vuota o parzialmente riempita, e in
     * nessuno dei due casi contiene qualcosa che valga la pena salvare.
     */
    private function ripuliParziale(): void
    {
        if (! Schema::hasColumn('fornitori', self::COLONNA)) {
            return;
        }

        Log::warning('Migrazione parziale rilevata su [fornitori], la colonna viene rifatta', [
            'colonna' => self::COLONNA,
        ]);

        Schema::table('fornitori', function (Blueprint $table) {
            $table->dropColumn(self::COLONNA);
        });
    }
};
