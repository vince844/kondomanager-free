<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'concorre_base_ritenuta',
        'natura_riga_ritenuta',
    ];

    /**
     * Fase 1 del redesign ritenute (docs/design/f24_ritenute_design.md §2.4, M3).
     *
     * La base della ritenuta non coincide con l'imponibile IVA (contributo
     * cassa professionale escluso, rivalsa INPS GS inclusa, rimborsi art. 15
     * esclusi): serve un flag PER RIGA. Default true per non alterare il
     * comportamento delle righe esistenti — l'esclusione è sempre una scelta
     * esplicita, mai un default silenzioso.
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('righe_fattura', function (Blueprint $table) {
            $table->boolean('concorre_base_ritenuta')->default(true)->after('importo_iva');
            $table->string('natura_riga_ritenuta', 30)->nullable()->after('concorre_base_ritenuta');
        });
    }

    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn ($col) => Schema::hasColumn('righe_fattura', $col)
        );

        if (! empty($toDrop)) {
            Schema::table('righe_fattura', function (Blueprint $table) use ($toDrop) {
                $table->dropColumn(array_values($toDrop));
            });
        }
    }

    private function cleanupPartialMigration(): void
    {
        $orphans = array_filter(
            $this->columns,
            fn ($col) => Schema::hasColumn('righe_fattura', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [righe_fattura], cleaning up before re-running', [
            'table' => 'righe_fattura',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('righe_fattura', function (Blueprint $table) use ($orphans) {
            $table->dropColumn(array_values($orphans));
        });
    }
};
