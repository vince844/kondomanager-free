<?php

namespace App\Actions\Cassa;

use App\Models\Gestionale\Cassa;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;

class CreateCassaModelAction
{
    public function execute(Condominio $condominio, ContoContabile $conto, array $data): Cassa
    {
        return Cassa::create([
            'condominio_id'      => $condominio->id,
            'nome'               => $data['nome'],
            'tipo'               => $data['tipo'],
            'conto_contabile_id' => $conto->id,
            'attiva'             => true,
            'note'               => $data['note'] ?? null,
        ]);
    }
}