<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le impostazioni del condominio: le scelte dell'amministratore che valgono solo per un condominio, nella scheda «Impostazioni» dell'area «Condominio». Una
 * riga per condominio, una colonna per scelta, con il suo tipo e il suo valore di serie: le scelte che verranno (l'interruttore del portale, i permessi dei
 * condòmini) si aggiungono come colonne, ognuna con la sua migrazione. Senza la riga valgono i valori di serie: aprire la pagina non scrive niente.
 *
 * - `recupero_salita`: dopo una lettera, quando scadono rate nuove: `avanti` (la salita va avanti, di serie) o `ricomincia` (per le rate nuove si ricomincia).
 * - `recupero_suggerisce_azione`: il programma suggerisce la prossima azione per ogni persona (di serie) o la sceglie l'amministratore.
 * - `aggiornate_da`: chi le ha salvate l'ultima volta.
 *
 * Rieseguibile: la tabella, l'indice unico e ognuna delle due chiavi esterne sono statement distinti su MySQL (il DDL non è transazionale), una guardia
 * ciascuno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'condominio_id' => ['condomini', 'cascade'],
        'aggiornate_da' => ['users', 'set null'],
    ];

    private const UNICO = 'impostazioni_condominio_condominio_unico';

    public function up(): void
    {
        if (! Schema::hasTable('impostazioni_condominio')) {
            Schema::create('impostazioni_condominio', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->string('recupero_salita', 20)->default('avanti')
                    ->comment('Dopo una lettera, quando scadono rate nuove: avanti (la salita va avanti) o ricomincia (per le rate nuove si ricomincia).');
                $table->boolean('recupero_suggerisce_azione')->default(true)
                    ->comment('Il programma suggerisce la prossima azione per ogni persona; falso: la sceglie l\'amministratore.');
                $table->unsignedBigInteger('aggiornate_da')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('impostazioni_condominio', self::UNICO)) {
            Schema::table('impostazioni_condominio', fn (Blueprint $table) => $table->unique(['condominio_id'], self::UNICO));
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('impostazioni_condominio', $colonna)) {
                Schema::table('impostazioni_condominio', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "impostazioni_condominio_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impostazioni_condominio');
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
