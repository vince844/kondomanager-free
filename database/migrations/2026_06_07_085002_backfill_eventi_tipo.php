<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Critico su Windows / hosting condiviso: evita timeout se lanciato da UI/HTTP
        set_time_limit(0);

        if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement("
                UPDATE eventi
                SET tipo = JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))
                WHERE tipo IS NULL
                  AND JSON_EXTRACT(meta, '$.type') IS NOT NULL
            ");
        } else {
            // Per SQLite in testing o altri database, facciamo backfill manuale
            \App\Models\Evento::whereNull('tipo')
                ->whereNotNull('meta->type')
                ->chunkById(100, function ($eventi) {
                    foreach ($eventi as $evento) {
                        $evento->update(['tipo' => $evento->meta['type'] ?? null]);
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
