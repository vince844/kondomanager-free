<?php

namespace App\Actions\Cassa;

use App\Enums\TipoCassa;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;

class CreateCassaAccountAction
{
    public function execute(Condominio $condominio, array $data): ContoContabile
    {
        // 1. Mastro Liquidità (Codice 10)
        $mastro = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'codice' => '10'],
            [
                'nome' => 'Disponibilità Liquide', 
                'tipo' => 'attivo', 
                'categoria' => 'liquidita', 
                'ruolo' => 'mastro_liquidita',
                'livello' => 0, 
                'di_sistema' => true
            ]
        );

        // 2. Calcolo Codice
        $countFigli = ContoContabile::where('condominio_id', $condominio->id)
            ->where('parent_id', $mastro->id)->count();
        
        $prossimoCodice = '10.' . str_pad($countFigli + 1, 2, '0', STR_PAD_LEFT);

        // 3. Logica Semantica Ruolo (DRY: Usa l'Enum!)
        $ruoloConto = TipoCassa::getRuoloFromValue($data['tipo']);

        // 4. Creazione
        return ContoContabile::create([
            'condominio_id' => $condominio->id,
            'parent_id'     => $mastro->id,
            'codice'        => $prossimoCodice,
            'nome'          => $data['nome'],
            'tipo'          => 'attivo',
            'categoria'     => 'liquidita',
            'ruolo'         => $ruoloConto,
            'livello'       => 1,
            'attivo'        => true,
            'di_sistema'    => true,
        ]);
    }
}