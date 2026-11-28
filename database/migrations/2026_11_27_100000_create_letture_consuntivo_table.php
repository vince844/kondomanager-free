<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le letture di fine anno dei sottocontatori di una voce: per ogni tabella della voce la lettura (kWh o m³), oppure la percentuale scritta, oppure
 * «per differenza» dal contatore generale; il contatore generale è la riga con `tabella_id` nullo. Una riga per voce, tabella ed esercizio. Il
 * conguaglio divide la spesa della voce con queste percentuali; il preventivo e le rate restano con i coefficienti della voce.
 *
 * Una guardia per la tabella, una per ogni colonna, una per l'indice e una per ogni chiave esterna: una migrazione interrotta a metà si riprende.
 */
return new class extends Migration
{
    private const TABELLA = 'letture_consuntivo';

    private const INDICE = 'letture_consuntivo_conto_tabella_esercizio_unique';

    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'conto_id'     => ['conti', 'cascade'],
        'tabella_id'   => ['tabelle', 'cascade'],
        'esercizio_id' => ['esercizi', 'cascade'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELLA)) {
            Schema::create(self::TABELLA, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conto_id');
                $table->unsignedBigInteger('tabella_id')->nullable();
                $table->unsignedBigInteger('esercizio_id');
                $table->decimal('lettura', 12, 3)->nullable();
                $table->decimal('percentuale', 5, 2)->nullable();
                $table->boolean('per_differenza')->default(false);
                $table->string('unita', 8)->default('kWh');
                $table->timestamps();
            });
        }

        $colonne = [
            'conto_id'       => fn (Blueprint $t) => $t->unsignedBigInteger('conto_id'),
            'tabella_id'     => fn (Blueprint $t) => $t->unsignedBigInteger('tabella_id')->nullable(),
            'esercizio_id'   => fn (Blueprint $t) => $t->unsignedBigInteger('esercizio_id'),
            'lettura'        => fn (Blueprint $t) => $t->decimal('lettura', 12, 3)->nullable(),
            'percentuale'    => fn (Blueprint $t) => $t->decimal('percentuale', 5, 2)->nullable(),
            'per_differenza' => fn (Blueprint $t) => $t->boolean('per_differenza')->default(false),
            'unita'          => fn (Blueprint $t) => $t->string('unita', 8)->default('kWh'),
        ];
        foreach ($colonne as $colonna => $aggiungi) {
            if (! Schema::hasColumn(self::TABELLA, $colonna)) {
                Schema::table(self::TABELLA, $aggiungi);
            }
        }

        if (! Schema::hasIndex(self::TABELLA, self::INDICE)) {
            Schema::table(self::TABELLA, fn (Blueprint $table) => $table->unique(['conto_id', 'tabella_id', 'esercizio_id'], self::INDICE));
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste(self::TABELLA, $colonna)) {
                Schema::table(self::TABELLA, function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, self::TABELLA . "_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABELLA);
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
