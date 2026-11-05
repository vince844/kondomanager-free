<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.2 (decisione 200): il registro dei documenti mandati a una persona. Una tabella sola per tutti i documenti: in questa beta la
 * ricevuta dell'incasso, stampata o inviata per email; dopo, i solleciti e gli altri avvisi (per questo `tipo` e `canale` sono stringhe e non
 * enum di database).
 *
 * Una riga per documento e per persona a ogni emissione: chi, quando, con quale canale, a quale indirizzo, con quali scelte (`dati`: lo stato
 * del bollo, la nota, e il contenuto della ricevuta emessa, che ogni copia riproduce), l'impronta SHA-256 del PDF emesso e lo stato. Per l'email la riga nasce `in_coda` e la porta avanti il registro delle
 * email (`LogSentMessage`), che scrive anche il legame `mail_log_id`.
 *
 * Rieseguibile: su MySQL la tabella, ognuno dei due indici e ognuna delle chiavi esterne sono statement distinti (la `create table` porta solo
 * le colonne e la chiave primaria; il DDL non è transazionale), quindi una guardia per la tabella, una per ogni indice e una per ogni chiave.
 * Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Nome dell'indice => colonne. */
    private const INDICI = [
        'registro_invii_documento_index'       => ['documentabile_type', 'documentabile_id'],
        'registro_invii_condominio_tipo_index' => ['condominio_id', 'tipo', 'emesso_da'],
    ];

    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'condominio_id' => ['condomini', 'cascade'],
        'anagrafica_id' => ['anagrafiche', 'set null'],
        'mail_log_id'   => ['mail_logs', 'set null'],
        'emesso_da'     => ['users', 'set null'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('registro_invii')) {
            Schema::create('registro_invii', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->unsignedBigInteger('anagrafica_id')->nullable()->comment('La persona a cui va il documento.');
                $table->string('tipo', 40)->comment('Il documento: ricevuta.');
                $table->string('canale', 20)->comment('stampa, email o nessuno (la scelta «non serve comunicarla»).');
                $table->string('documentabile_type');
                $table->unsignedBigInteger('documentabile_id');
                $table->string('numero', 50)->nullable()->comment('Il numero del documento: per la ricevuta il protocollo dell\'incasso.');
                $table->string('destinatario')->nullable()->comment('Gli indirizzi email del messaggio; nullo per la stampa.');
                $table->json('dati')->nullable()->comment('Le scelte di questa emissione (bollo, nota, causale) e il contenuto della ricevuta emessa.');
                $table->string('impronta', 64)->nullable()->comment('SHA-256 del PDF emesso.');
                $table->string('stato', 20)->comment('stampata, in_coda, inviata, non_partita, non_da_comunicare.');
                $table->text('errore')->nullable();
                $table->unsignedBigInteger('mail_log_id')->nullable();
                $table->unsignedBigInteger('emesso_da')->nullable();
                $table->timestamp('emesso_il');
                $table->timestamps();
            });
        }

        foreach (self::INDICI as $nome => $colonne) {
            if (! Schema::hasIndex('registro_invii', $nome)) {
                Schema::table('registro_invii', fn (Blueprint $table) => $table->index($colonne, $nome));
            }
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('registro_invii', $colonna)) {
                Schema::table('registro_invii', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "registro_invii_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registro_invii');
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $fk) {
                if (($fk->from ?? null) === $colonna) {
                    return true;
                }
            }

            return false;
        }

        return DB::selectOne('
            SELECT COUNT(*) AS n
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
              AND referenced_table_name IS NOT NULL
        ', [$tabella, $colonna])->n > 0;
    }
};
