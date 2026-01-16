<?php

namespace App\Actions\Cassa;

use App\Enums\TipoCassa;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;

class CreateCassaAccountAction
{
    public function execute(Condominio $condominio, array $data): ContoContabile
    {
        // 1. Trova la radice ATTIVO (che il CondominioService ha già creato)
        // Se per qualche motivo assurdo non esiste, la crea (fallback di sicurezza)
        $attivoRoot = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'codice' => '1000'],
            [
                'parent_id'   => null,
                'nome'        => 'ATTIVO',
                'tipo'        => 'attivo',
                'categoria'   => 'liquidita',
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 0
            ]
        );

        // 2. Trova o Crea il Gruppo "Disponibilità Liquide" SOTTO ATTIVO
        // Questo sarà il contenitore di tutte le casse
        $gruppoLiquidita = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'codice' => '1010'], // Codice figlio di 1000
            [
                'parent_id'   => $attivoRoot->id, // <--- Figlio di ATTIVO
                'nome'        => 'Disponibilità Liquide', 
                'tipo'        => 'attivo', 
                'categoria'   => 'liquidita', 
                'ruolo'       => 'mastro_liquidita',
                'livello'     => 1, 
                'di_sistema'  => true,
                'attivo'      => true
            ]
        );

        // 3. Calcolo Codice Progressivo (es. 1010.01, 1010.02)
        $countFigli = ContoContabile::where('condominio_id', $condominio->id)
            ->where('parent_id', $gruppoLiquidita->id)->count();
        
        $prossimoCodice = '1010.' . str_pad($countFigli + 1, 2, '0', STR_PAD_LEFT);

        // 4. Ruolo (Usa il tuo Enum helper)
        $ruoloConto = TipoCassa::getRuoloFromValue($data['tipo']);

        // 5. Creazione del Conto Cassa specifico
        return ContoContabile::create([
            'condominio_id' => $condominio->id,
            'parent_id'     => $gruppoLiquidita->id, 
            'codice'        => $prossimoCodice,
            'nome'          => $data['nome'],
            'tipo'          => 'attivo',      // ENUM OK
            'categoria'     => 'liquidita',   // ENUM OK
            'ruolo'         => $ruoloConto,
            'livello'       => 2,
            'attivo'        => true,
            'di_sistema'    => true,
        ]);
    }
}