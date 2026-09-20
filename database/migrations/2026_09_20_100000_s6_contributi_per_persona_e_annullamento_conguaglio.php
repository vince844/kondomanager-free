<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 10 (1.11.0-beta.31, S6). Due tabelle, una migrazione, per due fatti che S6 rende veri:
 *
 * 1. **`contributi_versati` — il già versato con la persona** (decisione 17). Il vincolo unico di luglio
 *    (`cv_target_immobile_unique` su target + unità) diceva «una copertura per unità»; da S4 il motore sconta
 *    per **persona** dove `anagrafica_id` è valorizzato, e da S6 la pagina dei contributi la scrive. Dopo una
 *    vendita l'acquirente deve poter registrare il suo versato sulla stessa voce accanto a quello del venditore:
 *    il vincolo diventa (target, unità, persona), con un nome **nuovo** (`cv_target_immobile_persona_unique`).
 *    Le righe storiche con `anagrafica_id` nullo restano valide; due righe nulle sulla stessa unità, prima
 *    vietate dal DB, ora sono ammesse dallo schema (NULL non collide in un UNIQUE, su MySQL e su SQLite) e
 *    restano vietate dal controller, che sostituisce integralmente le righe di una voce a ogni salvataggio.
 *
 * 2. **`subentri` — l'annullamento del conguaglio**. `nota_conguaglio` significa già «l'amministratore ha
 *    rinunciato alla coppia alla registrazione»; togliere la coppia dopo è un altro fatto, con la sua data e la
 *    sua ragione: `conguaglio_annullato_il`, `nota_annullamento_conguaglio`.
 *
 * Rieseguibile: gli indici si guardano con `Schema::getIndexes()` (mai `SHOW INDEX`, che fa cadere la suite
 * SQLite), le colonne con `hasColumn`. Il `down()` ripristina il vincolo di luglio col suo nome finché non
 * esistono due righe per persona sulla stessa unità; con quelle si ferma sul UNIQUE senza toccare le righe
 * (nessun percorso del prodotto chiama `down()`). Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const VECCHIO = 'cv_target_immobile_unique';
    private const NUOVO = 'cv_target_immobile_persona_unique';

    public function up(): void
    {
        if (Schema::hasTable('contributi_versati')) {
            if (! $this->indiceEsiste('contributi_versati', self::NUOVO)) {
                Schema::table('contributi_versati', function (Blueprint $table) {
                    $table->unique(['target_type', 'target_id', 'immobile_id', 'anagrafica_id'], self::NUOVO);
                });
            }
            if ($this->indiceEsiste('contributi_versati', self::VECCHIO)) {
                Schema::table('contributi_versati', function (Blueprint $table) {
                    $table->dropUnique(self::VECCHIO);
                });
            }
        }

        if (Schema::hasTable('subentri')) {
            Schema::table('subentri', function (Blueprint $table) {
                if (! Schema::hasColumn('subentri', 'conguaglio_annullato_il')) {
                    $table->timestamp('conguaglio_annullato_il')->nullable()->after('nota_conguaglio')
                        ->comment('Quando l\'amministratore ha tolto la coppia di conguaglio dopo la registrazione (S6); nullo se mai.');
                }
                if (! Schema::hasColumn('subentri', 'nota_annullamento_conguaglio')) {
                    $table->text('nota_annullamento_conguaglio')->nullable()->after('conguaglio_annullato_il');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contributi_versati')) {
            if (! $this->indiceEsiste('contributi_versati', self::VECCHIO)) {
                Schema::table('contributi_versati', function (Blueprint $table) {
                    $table->unique(['target_type', 'target_id', 'immobile_id'], self::VECCHIO);
                });
            }
            if ($this->indiceEsiste('contributi_versati', self::NUOVO)) {
                Schema::table('contributi_versati', function (Blueprint $table) {
                    $table->dropUnique(self::NUOVO);
                });
            }
        }
        if (Schema::hasTable('subentri')) {
            Schema::table('subentri', function (Blueprint $table) {
                foreach (['nota_annullamento_conguaglio', 'conguaglio_annullato_il'] as $colonna) {
                    if (Schema::hasColumn('subentri', $colonna)) {
                        $table->dropColumn($colonna);
                    }
                }
            });
        }
    }

    private function indiceEsiste(string $tabella, string $nome): bool
    {
        foreach (Schema::getIndexes($tabella) as $indice) {
            if (($indice['name'] ?? null) === $nome) {
                return true;
            }
        }

        return false;
    }
};
