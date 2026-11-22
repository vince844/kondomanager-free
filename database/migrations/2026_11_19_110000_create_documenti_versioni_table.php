<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §9 del progetto, decisione 518 (4)): un documento sostituito resta come versione di prima, in sola lettura, con la sua
 * impronta; occupa spazio come gli altri file.
 *
 * Una riga per file sostituito: dove sta adesso (`path`, nella cartella delle versioni), il nome con cui si scaricava, il tipo, la grandezza, l'impronta
 * sha256 del contenuto, quando e chi l'ha sostituito. La versione segue il documento (`documento_id` a cascata): tolto il documento, le righe vanno con lui
 * e i file si cancellano dopo il commit ({@see \App\Services\Documenti\VersioniDeiDocumenti}). Chi ha sostituito il file, se l'utente si elimina, resta
 * vuoto: il nome resta nella riga del registro delle modifiche.
 *
 * Rieseguibile: la tabella, l'indice e ogni chiave esterna hanno la loro guardia (su MySQL sono statement distinti e il DDL non è transazionale). Nel dataset
 * di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const INDICE = 'documenti_versioni_documento_index';

    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'documento_id' => ['documenti', 'cascade'],
        'sostituita_da' => ['users', 'set null'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('documenti_versioni')) {
            Schema::create('documenti_versioni', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('documento_id');
                $table->string('path')->comment('Il file di prima, nella cartella delle versioni del disco dei documenti.');
                $table->string('nome_originale')->comment('Il nome con cui il file si scaricava quando è stato sostituito.');
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size')->comment('In byte: conta nello spazio usato dei documenti.');
                $table->char('impronta', 64)->comment('sha256 del contenuto del file.');
                $table->dateTime('sostituita_il')->comment('UTC.');
                $table->unsignedBigInteger('sostituita_da')->nullable()->comment('Chi ha caricato il file nuovo; vuoto se l\'utente non c\'è più.');
            });
        }

        if (! Schema::hasIndex('documenti_versioni', self::INDICE)) {
            Schema::table('documenti_versioni', fn (Blueprint $table) => $table->index(['documento_id', 'sostituita_il'], self::INDICE));
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->chiaveEsiste('documenti_versioni', $colonna)) {
                Schema::table('documenti_versioni', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "documenti_versioni_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('documenti_versioni');
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function chiaveEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $chiave) {
                if (($chiave->from ?? null) === $colonna) {
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
