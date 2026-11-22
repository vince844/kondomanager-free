<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §1.1 del progetto della tappa 2, decisioni 499 e 501): la memoria del condominio, il memo «Da portare in assemblea». L'origine
 * tiene il suo nome **copiato** («Fattura n. 45 · Idraulica Bianchi · € 1.800,00»), che resta se l'origine sparisce; con «Ricordamelo dal …» il memo è
 * anche un compito dell'Action inbox (`evento_id`), e `assegnato_a` lo fa trovare a «Le mie».
 *
 * Rieseguibile: una guardia per la tabella, ogni indice e ogni chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'assemblee_memo' => [
            'assemblee_memo_condominio_stato_index' => [['condominio_id', 'stato'], false],
            'assemblee_memo_origine_index' => [['origine_tipo', 'origine_id'], false],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'assemblee_memo' => [
            'condominio_id' => ['condomini', 'cascade'],
            'palazzina_id' => ['palazzine', 'set null'],
            'scala_id' => ['scale', 'set null'],
            'assemblea_id' => ['assemblee', 'set null'],
            'assegnato_a' => ['users', 'set null'],
            'evento_id' => ['eventi', 'set null'],
            'punto_id' => ['assemblee_punti', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('assemblee_memo')) {
            Schema::create('assemblee_memo', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->string('ambito', 20)->default('condominio');
                $table->unsignedBigInteger('palazzina_id')->nullable();
                $table->unsignedBigInteger('scala_id')->nullable();
                $table->text('testo')->comment('Il punto proposto, riscritto se serve.');
                $table->text('nota')->nullable()->comment('La nota dello studio: resta nello studio.');
                $table->string('origine_tipo', 40)->nullable()->comment('Il nome stabile del registro dell\'origine.');
                $table->unsignedBigInteger('origine_id')->nullable();
                $table->string('origine_nome')->nullable()->comment('Il nome dell\'origine copiato: resta se l\'origine sparisce.');
                $table->unsignedBigInteger('assemblea_id')->nullable()->comment('Per quale assemblea; vuota vuol dire la prossima.');
                $table->date('ricordami_dal')->nullable()->comment('Con la data il memo è anche un compito (decisione 499 (1)).');
                $table->string('a_chi', 20)->default('me')->comment('me, studio o collaboratore (decisione 501).');
                $table->unsignedBigInteger('assegnato_a')->nullable();
                $table->unsignedBigInteger('evento_id')->nullable()->comment('Il compito dell\'Action inbox.');
                $table->string('stato', 20)->default('in_attesa')->comment('in_attesa, nel_punto, deliberato, non_serve.');
                $table->unsignedBigInteger('punto_id')->nullable()->comment('Il punto in cui è entrato.');
                $table->dateTime('chiuso_il')->nullable();
                $table->text('chiuso_motivo')->nullable();
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
        Schema::dropIfExists('assemblee_memo');
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
