<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coda 385: i dispositivi da cui un utente è già entrato, per avvisarlo quando entra da uno nuovo.
 *
 * Un dispositivo è un gettone casuale tenuto in un cookie del browser: qui c'è solo la sua impronta (sha256), mai il gettone. Accanto, la
 * descrizione del browser e del sistema, l'ultimo indirizzo e la prima e l'ultima volta. Le righe non viste da tredici mesi le toglie la
 * pulizia notturna (`model:prune`, `DispositivoConosciuto::prunable()`): l'indirizzo è un dato personale.
 *
 * Rieseguibile: su MySQL la tabella, l'indice e la chiave esterna sono statement distinti e il DDL non è transazionale, quindi una guardia per
 * ognuno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const INDICE = 'dispositivi_conosciuti_user_impronta_unique';

    public function up(): void
    {
        if (! Schema::hasTable('dispositivi_conosciuti')) {
            Schema::create('dispositivi_conosciuti', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('impronta', 64)->comment('sha256 del gettone del cookie: il gettone non si conserva.');
                $table->string('descrizione', 120)->nullable()->comment('Browser e sistema, letti dallo user agent.');
                $table->string('ultimo_indirizzo', 45)->nullable();
                $table->timestamp('prima_volta')->nullable();
                $table->timestamp('ultima_volta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('dispositivi_conosciuti', self::INDICE)) {
            Schema::table('dispositivi_conosciuti', fn (Blueprint $table) => $table->unique(['user_id', 'impronta'], self::INDICE));
        }

        if (! $this->foreignKeyEsiste('dispositivi_conosciuti', 'user_id')) {
            Schema::table('dispositivi_conosciuti', function (Blueprint $table) {
                $table->foreign('user_id', 'dispositivi_conosciuti_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivi_conosciuti');
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
