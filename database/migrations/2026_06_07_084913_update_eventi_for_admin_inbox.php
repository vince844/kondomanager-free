<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private array $columns = [
        'tipo',
        'eventable_type',
        'eventable_id',
        'priorita',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('eventi', function (Blueprint $table) {
            // Tipo evento — isola gli eventi specifici come i commenti dagli altri eventi esistenti.
            $table->string('tipo', 60)->nullable()->index()->after('id');

            // Relazione polimorfica generica verso l'entità che ha generato l'evento.
            // Nullable: gli eventi legacy (F24, rate, scadenze) non hanno un eventable.
            $table->string('eventable_type')->nullable()->after('tipo');
            $table->unsignedBigInteger('eventable_id')->nullable()->after('eventable_type');

            // Priorità visiva nell'inbox.
            // NULLABLE senza default: i record esistenti ricevono NULL
            $table->enum('priorita', ['alta', 'normale', 'bassa'])
                  ->nullable()
                  ->after('eventable_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('eventi', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('eventi', function (Blueprint $table) use ($toDrop) {
                $table->dropColumn(array_values($toDrop));
            });
        }
    }

    /**
     * Rileva ed elimina colonne orfane lasciate da un'esecuzione parziale precedente.
     */
    private function cleanupPartialMigration(): void
    {
        $orphans = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('eventi', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [eventi], cleaning up before re-running', [
            'table'   => 'eventi',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('eventi', function (Blueprint $table) use ($orphans) {
            $table->dropColumn(array_values($orphans));
        });
    }
};
