<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §0.4 e §1.1 del progetto della tappa 2): il preventivo «fermato» per l'assemblea, nella forma minima della versione del
 * rendiconto (`rendiconto_versioni`): un numero per esercizio, lo stato (presentata, approvata, sostituita), le cifre in centesimi e l'impronta, i PDF
 * conservati. Oggi ciò che i condòmini ricevono per il preventivo non resta da nessuna parte. L'approvazione dalla delibera viene dopo.
 *
 * Rieseguibile: una guardia per la tabella, l'indice unico e ogni chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'preventivo_versioni' => [
            'preventivo_versioni_numero_unico' => [['esercizio_id', 'numero'], true],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'preventivo_versioni' => [
            'condominio_id' => ['condomini', 'restrict'],
            'esercizio_id' => ['esercizi', 'restrict'],
            'gestione_id' => ['gestioni', 'set null'],
            'piano_rate_id' => ['piani_rate', 'set null'],
            'punto_id' => ['assemblee_punti', 'set null'],
            'creata_da' => ['users', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('preventivo_versioni')) {
            Schema::create('preventivo_versioni', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->unsignedBigInteger('esercizio_id');
                $table->unsignedBigInteger('gestione_id')->nullable();
                $table->unsignedBigInteger('piano_rate_id')->nullable()->comment('Il piano ordinario in bozza, se c\'è.');
                $table->unsignedInteger('numero');
                $table->string('stato', 20)->default('presentata')->comment('presentata, approvata o sostituita.');
                $table->json('cifre')->comment('Centesimi: le voci con l\'importo e la tabella, il riparto per tabella, le rate per persona.');
                $table->char('impronta', 64);
                $table->json('pdf')->nullable()->comment('I documenti conservati.');
                $table->unsignedBigInteger('punto_id')->nullable();
                $table->unsignedBigInteger('creata_da')->nullable();
                $table->timestamps();
            });
        }

        foreach (self::INDICI as $tabella => $indici) {
            foreach ($indici as $nome => [$colonne, $unico]) {
                if (! Schema::hasIndex($tabella, $nome)) {
                    Schema::table($tabella, fn (Blueprint $t) => $unico ? $t->unique($colonne, $nome) : $t->index($colonne, $nome));
                }
            }
        }

        foreach (self::CHIAVI as $tabella => $chiavi) {
            foreach ($chiavi as $colonna => [$arrivo, $regola]) {
                if (Schema::hasTable($arrivo) && ! $this->chiaveEsiste($tabella, $colonna)) {
                    Schema::table($tabella, function (Blueprint $t) use ($tabella, $colonna, $arrivo, $regola) {
                        $chiave = $t->foreign($colonna, "{$tabella}_{$colonna}_foreign")->references('id')->on($arrivo);
                        match ($regola) {
                            'cascade' => $chiave->cascadeOnDelete(),
                            'set null' => $chiave->nullOnDelete(),
                            default => $chiave->restrictOnDelete(),
                        };
                    });
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preventivo_versioni');
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
