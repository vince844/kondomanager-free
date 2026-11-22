<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, decisione 647): il mezzo di convocazione scelto **per questa assemblea**, nella riga della tabella dei convocati, senza toccare la
 * scheda della persona (`anagrafiche.mezzo_convocazione`): una riga per assemblea e per persona, che vale finché l'avviso non è spedito e prende il posto del
 * mezzo della scheda o della proposta. Con «Ricordalo nella sua scheda» il mezzo si scrive anche nella scheda, e questa riga non serve più.
 *
 * Rieseguibile: una guardia per la tabella, l'indice unico e ogni chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la
 * interrompe dopo la tabella.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'assemblee_mezzi_scelti' => [
            'assemblee_mezzi_scelti_unico' => [['assemblea_id', 'anagrafica_id'], true],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'assemblee_mezzi_scelti' => [
            'assemblea_id' => ['assemblee', 'cascade'],
            'anagrafica_id' => ['anagrafiche', 'cascade'],
            'created_by' => ['users', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('assemblee_mezzi_scelti')) {
            Schema::create('assemblee_mezzi_scelti', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assemblea_id');
                $table->unsignedBigInteger('anagrafica_id');
                $table->string('mezzo', 20)->comment('pec, raccomandata, a_mano o fax (art. 66 c. 3 disp. att.): vale per questa assemblea, finché l\'avviso non è spedito.');
                $table->unsignedBigInteger('created_by')->nullable();
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
        Schema::dropIfExists('assemblee_mezzi_scelti');
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
