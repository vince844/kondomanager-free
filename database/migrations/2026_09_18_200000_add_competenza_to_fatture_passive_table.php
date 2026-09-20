<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 1 (1.11.0-beta.31): il periodo di competenza della fattura come colonne vere.
 *
 * `dati_extra->competenza = {dal, al}` era scritto dal 2026 e mai letto (§1.4 del progetto
 * `docs/subentro_e_competenza_temporale.md`: «un campo morto già in colonna»). Diventa
 * `competenza_dal` / `competenza_al`, date nullable — nullo significa «scendi al gradino successivo
 * della cascata di D3». Sullo straordinario e sugli ad personam è il **primo gradino**; su una fattura
 * di un capitolo ordinario si raccoglie ma non guida le rate (decisione 19).
 *
 * Il travaso dal JSON si fa qui, una volta, riga per riga e **a log**: nel database di sviluppo 0 fatture
 * su 44 lo valorizzano, ma un'installazione potrebbe. Si travasa solo dove entrambi gli estremi sono
 * presenti e le colonne nuove sono ancora nulle: rieseguire non travasa due volte (invariante 23).
 *
 * Rieseguibile: guardia `hasColumn` per colonna; nessuna FK. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fatture_passive')) {
            return;
        }

        Schema::table('fatture_passive', function (Blueprint $table) {
            if (! Schema::hasColumn('fatture_passive', 'competenza_dal')) {
                $table->date('competenza_dal')->nullable()->after('data_competenza_originaria')
                    ->comment('Competenza dichiarata: primo giorno (B2). Nullo = cascata di D3.');
            }
            if (! Schema::hasColumn('fatture_passive', 'competenza_al')) {
                $table->date('competenza_al')->nullable()->after('competenza_dal')
                    ->comment('Competenza dichiarata: ultimo giorno (B2). Uguale a competenza_dal per una delibera.');
            }
        });

        $this->travasaDaDatiExtra();
    }

    public function down(): void
    {
        if (! Schema::hasTable('fatture_passive')) {
            return;
        }
        Schema::table('fatture_passive', function (Blueprint $table) {
            foreach (['competenza_al', 'competenza_dal'] as $c) {
                if (Schema::hasColumn('fatture_passive', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }

    /** `dati_extra->competenza->{dal,al}` → colonne, solo dove entrambi presenti e le colonne ancora nulle. */
    private function travasaDaDatiExtra(): void
    {
        $righe = DB::table('fatture_passive')
            ->whereNotNull('dati_extra')
            ->whereNull('competenza_dal')
            ->whereNull('competenza_al')
            ->get(['id', 'dati_extra']);

        $travasate = 0;
        foreach ($righe as $r) {
            $extra = is_string($r->dati_extra) ? json_decode($r->dati_extra, true) : (array) $r->dati_extra;
            $dal = $extra['competenza']['dal'] ?? null;
            $al = $extra['competenza']['al'] ?? null;
            if (! is_string($dal) || ! is_string($al) || $dal === '' || $al === '') {
                continue;
            }
            $dal = substr($dal, 0, 10);
            $al = substr($al, 0, 10);
            if ($al < $dal) {
                Log::warning("B2 migrazione 1: fattura {$r->id} con competenza rovesciata in dati_extra ({$dal} > {$al}): non travasata.");
                continue;
            }
            DB::table('fatture_passive')->where('id', $r->id)->update(['competenza_dal' => $dal, 'competenza_al' => $al]);
            Log::info("B2 migrazione 1: fattura {$r->id} competenza travasata da dati_extra: {$dal} → {$al}.");
            $travasate++;
        }

        Log::info("B2 migrazione 1: travaso competenza da dati_extra completato, {$travasate} fatture aggiornate su {$righe->count()} candidate.");
    }
};
