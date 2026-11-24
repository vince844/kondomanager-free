<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.14 (Coda 755 (1), decisione 698 (1)): il registro degli errori nel database, accanto al file `storage/logs/laravel.log`.
 *
 * - `errori`: un errore per impronta (il tipo, il punto del programma, il messaggio senza numeri né valori, la versione), con quante volte, la prima e
 *   l'ultima, e lo stato per la scheda «Errori» («nuovo», «avvisato», «visto»).
 * - `errori_volte`: una riga per volta, con il codice di sei caratteri che la pagina d'errore mostra, l'ora, la pagina con i segnaposto della rotta, il ruolo
 *   di chi l'ha incontrata (mai il nome), le versioni, il messaggio ripulito e la traccia senza valori; e l'avviso, se qualcuno l'ha mandato (chi, quando,
 *   con quale ruolo, la nota).
 *
 * Le righe si tengono 90 giorni (la pulizia di ogni notte, `model:prune`). Rieseguibile: una guardia per ogni tabella, indice e chiave esterna. Nel dataset
 * di `UpgradeMigrationsRerunTest`, con un `it()` che la interrompe dopo le tabelle.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'errori' => [
            'errori_impronta_unico' => [['impronta'], true],
            'errori_ultima_volta_indice' => [['ultima_volta_il'], false],
        ],
        'errori_volte' => [
            'errori_volte_codice_indice' => [['codice'], false],
            'errori_volte_errore_avvenuto_indice' => [['errore_id', 'avvenuto_il'], false],
            'errori_volte_avvenuto_indice' => [['avvenuto_il'], false],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'errori_volte' => [
            'errore_id' => ['errori', 'cascade'],
            'avvisato_da' => ['users', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('errori')) {
            Schema::create('errori', function (Blueprint $table) {
                $table->id();
                $table->string('impronta', 40)->comment('sha1 del tipo, del punto del programma, del messaggio senza numeri né valori e della versione');
                $table->string('classe', 255);
                $table->text('messaggio')->comment('ripulito: senza i valori delle query, l\'indirizzo del database, email, codici fiscali, IBAN');
                $table->string('file', 500)->nullable();
                $table->unsignedInteger('riga')->nullable();
                $table->unsignedInteger('volte')->default(0)->comment('anche quelle oltre le 200 al giorno, che non hanno una riga');
                $table->dateTime('prima_volta_il');
                $table->dateTime('ultima_volta_il');
                $table->string('stato', 20)->default('nuovo')->comment('nuovo, avvisato, visto');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('errori_volte')) {
            Schema::create('errori_volte', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('errore_id');
                $table->string('codice', 6);
                $table->dateTime('avvenuto_il');
                $table->string('metodo', 10)->nullable();
                $table->string('pagina', 500)->nullable()->comment('la rotta con i segnaposto, mai l\'indirizzo vero né la sua coda');
                $table->string('rotta', 255)->nullable();
                $table->string('azione', 255)->nullable();
                $table->string('ruolo', 50)->nullable()->comment('il ruolo di chi ha incontrato l\'errore, mai il nome');
                $table->string('versione', 50)->nullable();
                $table->string('versione_php', 20)->nullable();
                $table->string('versione_laravel', 20)->nullable();
                $table->string('database', 20)->nullable();
                $table->string('classe', 255);
                $table->text('messaggio');
                $table->text('causa')->nullable();
                $table->string('file', 500)->nullable();
                $table->unsignedInteger('riga')->nullable();
                $table->longText('traccia')->nullable();
                $table->unsignedBigInteger('avvisato_da')->nullable();
                $table->dateTime('avvisato_il')->nullable();
                $table->string('avvisato_ruolo', 50)->nullable();
                $table->string('nota_avviso', 300)->nullable();
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
        Schema::dropIfExists('errori_volte');
        Schema::dropIfExists('errori');
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
