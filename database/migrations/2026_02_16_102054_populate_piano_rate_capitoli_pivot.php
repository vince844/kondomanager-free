<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration {

    public function up(): void
    {
        // Critico su Windows / hosting condiviso: questa migration gira dentro
        // una richiesta HTTP (Artisan::call in SystemUpgradeController).
        // max_execution_time = 60s di default — insufficiente con molti piani rate.
        set_time_limit(0);

        // PULIZIA TOTALE (siamo in Beta, ricalcoliamo da zero per sicurezza)
        DB::table('piano_rate_capitoli')->truncate();

        // Recuperiamo tutti i piani attivi via cursor (lazy) invece di ->get()
        // per evitare di caricare potenzialmente migliaia di record in RAM
        DB::table('piani_rate')
            ->where('attivo', true)
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $piano) {

                // Capitoli RADICE della gestione per questo piano
                $capitoliRadice = DB::table('conti')
                    ->join('piani_conti', 'conti.piano_conto_id', '=', 'piani_conti.id')
                    ->where('piani_conti.gestione_id', $piano->gestione_id)
                    ->whereNull('conti.parent_id')
                    ->select('conti.id', 'conti.importo')
                    ->get();

                foreach ($capitoliRadice as $capitolo) {

                    $importoReale = $capitolo->importo;

                    // Padre vuoto = contenitore: sommiamo i figli diretti
                    if ($importoReale == 0) {
                        $importoReale = DB::table('conti')
                            ->where('parent_id', $capitolo->id)
                            ->sum('importo');
                    }

                    DB::table('piano_rate_capitoli')->insert([
                        'piano_rate_id' => $piano->id,
                        'conto_id'      => $capitolo->id,
                        'importo'       => $importoReale > 0 ? $importoReale : 0,
                        'note'          => $importoReale > 0
                                            ? 'Migrazione V1.9 (Totale Aggregato)'
                                            : 'Capitolo vuoto',
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }

                Log::info("Pivot piano_rate_capitoli popolata per piano ID: {$piano->id}");
            });
    }

    public function down(): void
    {
        DB::table('piano_rate_capitoli')->truncate();
    }
};