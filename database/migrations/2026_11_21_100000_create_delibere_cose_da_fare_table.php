<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, il seguito delle delibere): ciò che resta da fare dopo una delibera (decisione 522 (1)). Una riga per cosa: proposta dal
 * programma («Crea il piano straordinario», «Registra la dispensa», «Scrivi la regola», «Avvisa i condòmini») o scritta dall'amministratore («Firma con
 * Serramenti Marino»), con la data e la persona; con la data è anche un compito dell'Action inbox (`evento_id`). Fatta dal programma quando la scheda nasce
 * con la delibera (`oggetto_tipo`, `oggetto_id`), o con «Segna eseguita» (la data, la nota, il documento facoltativo); «Non serve più» con il motivo.
 *
 * La delibera con la chiave `restrict` (una delibera con le sue cose da fare non si elimina); l'utente, il compito e il documento con `set null`.
 *
 * Rieseguibile: una guardia per la tabella, per ogni indice e per ogni chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la
 * interrompe dopo la tabella.
 */
return new class extends Migration
{
    /** Nome dell'indice => colonne. */
    private const INDICI = [
        'delibere_cose_da_fare_delibera_index' => ['delibera_id'],
        'delibere_cose_da_fare_condominio_stato_index' => ['condominio_id', 'stato'],
        'delibere_cose_da_fare_oggetto_index' => ['oggetto_tipo', 'oggetto_id'],
    ];

    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'delibera_id' => ['delibere', 'restrict'],
        'condominio_id' => ['condomini', 'restrict'],
        'assegnato_a' => ['users', 'set null'],
        'evento_id' => ['eventi', 'set null'],
        'fatta_da' => ['users', 'set null'],
        'documento_id' => ['documenti', 'set null'],
        'created_by' => ['users', 'set null'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('delibere_cose_da_fare')) {
            Schema::create('delibere_cose_da_fare', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('delibera_id');
                $table->unsignedBigInteger('condominio_id')->comment('Copiato dalla delibera, per la dashboard.');
                $table->string('tipo', 40)->comment('crea_piano_straordinario, prepara_ripianamento, registra_dispensa, scrivi_regola, rifai_versione, '
                    . 'avvisa_condomini, fuori_dal_programma, dopo_il_rifiuto.');
                $table->string('testo', 255);
                $table->date('entro_il')->nullable()->comment('Con la data, il compito dell\'Action inbox.');
                $table->string('a_chi', 20)->default('me')->comment('me, studio, collaboratore: come il memo.');
                $table->unsignedBigInteger('assegnato_a')->nullable();
                $table->unsignedBigInteger('evento_id')->nullable()->comment('Il compito.');
                $table->string('stato', 20)->default('da_fare')->comment('da_fare, fatta, non_serve.');
                $table->date('fatta_il')->nullable();
                $table->unsignedBigInteger('fatta_da')->nullable();
                $table->text('nota')->nullable()->comment('La nota di «Segna eseguita».');
                $table->text('chiusa_motivo')->nullable()->comment('Il motivo di «Non serve più» o della chiusura di una respinta.');
                $table->unsignedBigInteger('documento_id')->nullable()->comment('Il documento facoltativo di «Segna eseguita».');
                $table->string('oggetto_tipo', 40)->nullable()->comment('La scheda che l\'ha eseguita, con il nome stabile del registro.');
                $table->unsignedBigInteger('oggetto_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        foreach (self::INDICI as $nome => $colonne) {
            if (! Schema::hasIndex('delibere_cose_da_fare', $nome)) {
                Schema::table('delibere_cose_da_fare', fn (Blueprint $t) => $t->index($colonne, $nome));
            }
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->chiaveEsiste('delibere_cose_da_fare', $colonna)) {
                Schema::table('delibere_cose_da_fare', function (Blueprint $t) use ($colonna, $arrivo, $regola) {
                    $chiave = $t->foreign($colonna, "delibere_cose_da_fare_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'set null' ? $chiave->nullOnDelete() : $chiave->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delibere_cose_da_fare');
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function chiaveEsiste(string $tabella, string $colonna): bool
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
