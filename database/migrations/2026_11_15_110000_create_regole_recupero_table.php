<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le regole del recupero dei crediti di ogni condominio, con la loro storia: una riga per ogni regola, dal giorno in cui comincia. Il tasso degli
 * interessi di mora («legale», la tabella dell'anno, o un tasso scritto con la sua fonte e il riferimento), i giorni dopo la scadenza da cui decorrono,
 * i giorni di tolleranza prima di sollecitare, il termine di serie delle lettere. Una regola nuova non cambia le vecchie: un giorno del 2026 si legge
 * con la regola del 2026. Senza nessuna riga vale la legge: il tasso legale, dal giorno dopo la scadenza.
 *
 * Le regole sono del condominio: eliminato il condominio, se ne vanno con lui. Chi le ha scritte può essere eliminato: restano. Nessun riempimento: i
 * condomini di oggi partono senza regole, che è il loro vero.
 *
 * Rieseguibile: la tabella, l'indice unico e ogni chiave esterna sono statement distinti su MySQL (il DDL non è transazionale), una guardia ciascuno.
 */
return new class extends Migration
{
    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'condominio_id' => ['condomini', 'cascade'],
        'creata_da' => ['users', 'set null'],
    ];

    private const UNICO = 'regole_recupero_dal_unico';

    public function up(): void
    {
        if (! Schema::hasTable('regole_recupero')) {
            Schema::create('regole_recupero', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->date('dal')->comment('Il primo giorno in cui la regola vale; vale fino al giorno prima della regola dopo.');
                $table->string('tasso_tipo', 10)->comment('legale (la tabella dell\'anno) o scritto (la misura qui sotto, con la sua fonte).');
                $table->decimal('tasso_annuo', 7, 4)->nullable()->comment('Il tasso scritto, in punti percentuali l\'anno; vuoto con il tasso legale.');
                $table->string('fonte', 20)->nullable()->comment('Da dove viene il tasso scritto: regolamento, delibera, altro.');
                $table->string('riferimento', 255)->nullable()->comment('L\'articolo del regolamento, la data e il verbale della delibera.');
                $table->unsignedSmallInteger('giorni_decorrenza')->default(0)->comment('I giorni dopo la scadenza che il regolamento concede prima degli interessi.');
                $table->unsignedSmallInteger('giorni_tolleranza')->nullable()->comment('I giorni dopo la scadenza prima di sollecitare; vuoto: nessuno.');
                $table->unsignedSmallInteger('termine_lettere_giorni')->nullable()->comment('Il termine di serie delle lettere, in giorni; vuoto: quello della finestra.');
                $table->unsignedBigInteger('creata_da')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('regole_recupero', self::UNICO)) {
            Schema::table('regole_recupero', fn (Blueprint $table) => $table->unique(['condominio_id', 'dal'], self::UNICO));
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('regole_recupero', $colonna)) {
                Schema::table('regole_recupero', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "regole_recupero_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('regole_recupero');
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
