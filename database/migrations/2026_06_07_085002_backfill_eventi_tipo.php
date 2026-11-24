<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Evento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Critico su Windows / hosting condiviso: evita timeout se lanciato da UI/HTTP
        \App\Support\LimiteDiTempo::alza(0);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                UPDATE eventi
                SET tipo = JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))
                WHERE tipo IS NULL
                  AND JSON_EXTRACT(meta, '$.type') IS NOT NULL
            ");
        } else {
            // Per SQLite in testing o altri database, facciamo backfill manuale
            Evento::whereNull('tipo')
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
