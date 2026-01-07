<?php

namespace App\Actions\Cassa;

use App\Helpers\MoneyHelper;
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
            'descrizione'        => $data['descrizione'] ?? null,
            'tipo'               => $data['tipo'],
            'conto_contabile_id' => $conto->id,
            'saldo_iniziale' => isset($data['saldo_iniziale']) 
                                ? MoneyHelper::toCents($data['saldo_iniziale']) 
                                : 0,
            'attiva'             => true,
            'note'               => $data['note'] ?? null,
        ]);
    }
}