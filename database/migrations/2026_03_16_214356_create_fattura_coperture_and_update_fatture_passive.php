<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration v1.9 — Debiti Pregressi: Double Lock & Coperture
 *
 * Estende fatture_passive con:
 * - data_competenza_originaria  Motore Sentinella Prescrizione (5 anni)
 * - saldo_patrimoniale_id       FK al saldo in saldi[] che questa fattura estingue
 *
 * Crea fattura_coperture:
 * - Una riga per ogni fonte di copertura (supporta split misto)
 * - stato pianificata/confermata per il doppio semaforo del widget
 *
 * Nomi tabelle verificati sul DB reale:
 * saldi, conti, conti_contabili, fatture_passive
 *
 * is_pregresso è già presente su fatture_passive (migration 154).
 * Non viene toccato.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Estende fatture_passive ───────────────────────────────────────────
        Schema::table('fatture_passive', function (Blueprint $table) {

            // Data reale di origine del debito.
            // Nullable: NULL = fattura corrente, sentinella non applicabile.
            // Viene popolata solo quando is_pregresso = true.
            // Motore della Sentinella: WHERE data_competenza_originaria < NOW() - INTERVAL 5 YEAR
            $table->date('data_competenza_originaria')
                  ->nullable()
                  ->after('is_pregresso')
                  ->index()
                  ->comment('Popolato solo se is_pregresso=true. Motore Sentinella Prescrizione 5 anni.');

            // FK al saldo in "saldi" che questa fattura pregressa va a estinguere.
            // Percorso: fattura_passiva → saldi (il debito patrimoniale ereditato).
            // NULL = fattura corrente oppure sopravvenienza (non lega allo SP di apertura).
            //
            // onDelete RESTRICT: non si può cancellare un saldo se ha fatture pregresse
            // ancora aperte collegate. Protezione contabile intenzionale.
            $table->foreignId('saldo_patrimoniale_id')
                  ->nullable()
                  ->after('data_competenza_originaria')
                  ->constrained('saldi')
                  ->restrictOnDelete()
                  ->comment('FK verso saldi: il debito patrimoniale che questa fattura estingue.');
        });

        // ── Crea fattura_coperture ────────────────────────────────────────────
        Schema::create('fattura_coperture', function (Blueprint $table) {
            $table->id();

            // Fattura a cui appartiene questa riga di copertura.
            // CASCADE: se la fattura viene cancellata, le coperture spariscono con lei.
            $table->foreignId('fattura_passiva_id')
                  ->constrained('fatture_passive')
                  ->cascadeOnDelete();

            // Tipo di fonte da cui arrivano i soldi per coprire questa fattura pregressa.
            $table->enum('tipo_copertura', [
                'rata_0',         // Provvista da saldi iniziali condòmini (Rata 0 deliberata)
                'sopravvenienza', // Convertita in spesa corrente: va nel preventivo dell'anno
                'fondo_riserva',  // Stornata da un fondo accantonamento patrimoniale
            ])->index();

            // Importo coperto da questa fonte, in centesimi.
            // Coerente con tutto il resto del DB (bigInteger).
            $table->bigInteger('importo');

            // ── CUORE DEL WIDGET DOPPIO SEMAFORO ─────────────────────────────
            //
            // pianificata → La Rata 0 è deliberata ma non ancora incassata fisicamente.
            //               Semaforo CONTABILE verde (copertura esiste sulla carta),
            //               Semaforo FINANZIARIO giallo (liquidità non ancora in cassa).
            //               Questo è il caso: "ho registrato il debito pregresso ma
            //               i condòmini non hanno ancora versato la Rata 0."
            //
            // confermata  → I soldi sono fisicamente in cassa.
            //               Entrambi i semafori VERDI.
            //               Passa da pianificata → confermata automaticamente quando
            //               viene registrato l'incasso della Rata 0 corrispondente
            //               (hook in IncassoRataAction / RataZeroService).
            //
            $table->enum('stato', ['pianificata', 'confermata'])
                  ->default('pianificata')
                  ->comment('pianificata=Rata0 deliberata non incassata. confermata=liquidità reale disponibile.');

            // ── FK CONTESTUALI — una sola valorizzata per riga ───────────────
            // Pattern scelto vs nullableMorphs: FK esplicite per tipo permettono
            // aggregazioni di fine anno senza ambiguità (es. "totale stornato da fondi").

            // tipo_copertura = rata_0
            // Punta alla riga in "saldi" del condòmino specifico che copre questa fattura.
            // Verificato: "saldi" è la tabella reale (confermato da FK esistenti nel DB).
            $table->foreignId('saldo_id')
                  ->nullable()
                  ->constrained('saldi')
                  ->nullOnDelete()
                  ->comment('Valorizzato se tipo_copertura=rata_0. FK verso saldi del condòmino.');

            // tipo_copertura = sopravvenienza
            // Punta alla voce del preventivo corrente (tabella "conti") su cui la spesa
            // viene riclassificata come costo dell'anno corrente.
            // Verificato: righe_fattura.conto_id → conti (stessa tabella, stesso schema).
            $table->foreignId('conto_id')
                  ->nullable()
                  ->constrained('conti')
                  ->nullOnDelete()
                  ->comment('Valorizzato se tipo_copertura=sopravvenienza. Voce preventivo corrente.');

            // tipo_copertura = fondo_riserva
            // Punta al conto contabile patrimoniale del fondo.
            // Verificato: in conti_contabili esistono già fondi (categoria=fondi, es. id=65
            // "Fondo Passate Gestioni" con ruolo=passate_gestioni).
            $table->foreignId('fondo_id')
                  ->nullable()
                  ->constrained('conti_contabili')
                  ->nullOnDelete()
                  ->comment('Valorizzato se tipo_copertura=fondo_riserva. FK verso conti_contabili fondi.');

            // Nota libera dell'amministratore sulla decisione presa.
            // Alimenta automaticamente la "Nota Sintetica" del rendiconto
            // (art. 1130-bis c.c.: obbligo di indicare le questioni pendenti).
            // Questo campo è il "Double Lock" documentale: la scelta è indelebile.
            $table->text('nota_amministratore')
                  ->nullable()
                  ->comment('Motivazione della scelta di copertura. Alimenta Nota Sintetica rendiconto.');

            $table->timestamps();

            // Indice composto per le aggregazioni di rendiconto e per il widget.
            // Es: "tutte le coperture di tipo fondo_riserva per questo condominio nell'anno"
            $table->index(['fattura_passiva_id', 'tipo_copertura'], 'idx_coperture_fattura_tipo');
            $table->index(['tipo_copertura', 'stato'], 'idx_coperture_tipo_stato');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fattura_coperture');

        Schema::table('fatture_passive', function (Blueprint $table) {
            $table->dropForeign(['saldo_patrimoniale_id']);
            $table->dropColumn([
                'data_competenza_originaria',
                'saldo_patrimoniale_id',
            ]);
        });
    }
};