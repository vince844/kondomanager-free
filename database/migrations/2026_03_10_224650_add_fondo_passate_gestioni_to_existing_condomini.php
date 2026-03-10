<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Esegue la Data Migration per inserire il conto "Passate Gestioni"
     * nei condomini creati con le versioni precedenti (<= 1.8.x).
     */
    public function up(): void
    {
        // 1. Recuperiamo tutti i condomini presenti a database
        $condomini = Condominio::all();

        foreach ($condomini as $condominio) {
            // 2. Troviamo la radice del PASSIVO per questo condominio
            $passivoRoot = ContoContabile::where('condominio_id', $condominio->id)
                ->whereNull('parent_id')
                ->where('nome', 'PASSIVO')
                ->first();

            if ($passivoRoot) {
                // 3. Creiamo il conto solo se non esiste già
                ContoContabile::firstOrCreate(
                    [
                        'condominio_id' => $condominio->id, 
                        'ruolo'         => 'passate_gestioni'
                    ],
                    [
                        'parent_id'   => $passivoRoot->id,
                        'codice'      => '2301',
                        'nome'        => 'Fondo Passate Gestioni',
                        'descrizione' => 'Fondo per debiti e spese di esercizi precedenti',
                        'tipo'        => 'passivo',
                        'categoria'   => 'fondi',
                        'di_sistema'  => true,
                        'attivo'      => true,
                        'livello'     => 1
                    ]
                );
            } else {
                Log::warning("Impossibile trovare la radice PASSIVO per il condominio ID: {$condominio->id} durante la migration.");
            }
        }
    }

    /**
     * Inverti la migrazione (Rollback).
     */
    public function down(): void
    {
        // Se facciamo rollback della 1.9, eliminiamo il conto di sistema creato
        ContoContabile::where('ruolo', 'passate_gestioni')->delete();
    }
};