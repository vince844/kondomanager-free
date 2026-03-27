<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $condomini = Condominio::all();

        foreach ($condomini as $condominio) {
            
            // Troviamo il nodo "PASSIVO" padre per agganciarlo
            $passivoRoot = ContoContabile::where('condominio_id', $condominio->id)
                ->whereNull('parent_id')
                ->where('nome', 'PASSIVO')
                ->first();

            if ($passivoRoot) {
                ContoContabile::firstOrCreate(
                    [
                        'condominio_id' => $condominio->id, 
                        'ruolo'         => 'debiti_erario_ritenute'
                    ],
                    [
                        'parent_id'   => $passivoRoot->id,
                        'codice'      => '2202', // Subito dopo i Debiti Fornitori
                        'nome'        => 'Debiti v/Erario per Ritenute',
                        'descrizione' => 'Trattenute operate da versare allo Stato tramite F24',
                        'tipo'        => 'passivo',
                        'categoria'   => 'debiti',
                        'di_sistema'  => true,
                        'attivo'      => true,
                        'livello'     => 1
                    ]
                );
                Log::info("Mastro Erario Ritenute creato per condominio ID: {$condominio->id}");
            }
        }
    }

    public function down(): void
    {
        ContoContabile::where('ruolo', 'debiti_erario_ritenute')->delete();
    }
};