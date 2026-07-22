<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'tipo_ritenuta',
        'natura_percipiente',
        'residente_fiscale',
        'regime_forfetario',
        'forfetario_dichiarato_il',
        'forfetario_riferimento',
        'provvigioni_base_ridotta',
        'provvigioni_dichiarazione_il',
    ];

    /**
     * Fase 1 del redesign ritenute (docs/design/f24_ritenute_design.md §2.4, M2).
     *
     * Additiva rispetto ai campi legacy (soggetto_ritenuta/perc_ritenuta/
     * perc_imponibile_ritenuta/codice_tributo): non li sostituisce, li affianca.
     * `codice_tributo` resta in tabella come override motivato, ma la fonte di
     * verità per 1019 vs 1020 diventa TipoRitenuta::codiceTributo(NaturaPercipiente).
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('fornitori', function (Blueprint $table) {
            $table->string('tipo_ritenuta', 30)->nullable()->after('codice_tributo');
            $table->string('natura_percipiente', 30)->nullable()->after('tipo_ritenuta');
            $table->boolean('residente_fiscale')->default(true)->after('natura_percipiente');
            $table->boolean('regime_forfetario')->default(false)->after('residente_fiscale');
            $table->date('forfetario_dichiarato_il')->nullable()->after('regime_forfetario');
            $table->string('forfetario_riferimento', 255)->nullable()->after('forfetario_dichiarato_il');
            $table->boolean('provvigioni_base_ridotta')->default(false)->after('forfetario_riferimento');
            $table->date('provvigioni_dichiarazione_il')->nullable()->after('provvigioni_base_ridotta');
        });

        $this->backfill();
    }

    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn ($col) => Schema::hasColumn('fornitori', $col)
        );

        if (! empty($toDrop)) {
            Schema::table('fornitori', function (Blueprint $table) use ($toDrop) {
                $table->dropColumn(array_values($toDrop));
            });
        }
    }

    /**
     * Backfill euristico e NON distruttivo, solo dove soggetto_ritenuta = true.
     * Design §2.4, M2. Dove l'euristica non riconosce un valore noto, resta
     * NULL: meglio un fornitore da completare a mano che un regime inventato.
     */
    private function backfill(): void
    {
        DB::table('fornitori')
            ->where('soggetto_ritenuta', true)
            ->where('perc_ritenuta', 4)
            ->update(['tipo_ritenuta' => 'appalto_4']);

        DB::table('fornitori')
            ->where('soggetto_ritenuta', true)
            ->where('perc_ritenuta', 20)
            ->update(['tipo_ritenuta' => 'lavoro_autonomo_20']);

        DB::table('fornitori')
            ->where('soggetto_ritenuta', true)
            ->where('codice_tributo', '1019')
            ->update(['natura_percipiente' => 'persona_fisica_irpef']);

        DB::table('fornitori')
            ->where('soggetto_ritenuta', true)
            ->where('codice_tributo', '1020')
            ->update(['natura_percipiente' => 'soggetto_ires']);
    }

    /**
     * Rileva ed elimina colonne orfane lasciate da un'esecuzione parziale precedente.
     * MySQL non supporta DDL transazionali: un timeout a metà lascia colonne
     * senza che la migration risulti registrata in `migrations`.
     */
    private function cleanupPartialMigration(): void
    {
        $orphans = array_filter(
            $this->columns,
            fn ($col) => Schema::hasColumn('fornitori', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [fornitori], cleaning up before re-running', [
            'table' => 'fornitori',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('fornitori', function (Blueprint $table) use ($orphans) {
            $table->dropColumn(array_values($orphans));
        });
    }
};
