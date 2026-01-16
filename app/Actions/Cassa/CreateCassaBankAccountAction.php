<?php

namespace App\Actions\Cassa;

use App\Models\Gestionale\Cassa;
use App\Models\ContoCorrente;

class CreateCassaBankAccountAction
{
    public function execute(Cassa $cassa, array $data): void
    {
        if ($data['tipo'] !== 'banca') {
            return;
        }

        $isPredefinito = filter_var($data['predefinito'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isPredefinito) {
            // Reset altri conti predefiniti
            $casseIds = Cassa::where('condominio_id', $cassa->condominio_id)
                ->where('tipo', 'banca')
                ->where('id', '!=', $cassa->id)
                ->pluck('id');

            ContoCorrente::where('contable_type', Cassa::class)
                ->whereIn('contable_id', $casseIds)
                ->update(['predefinito' => 0]);
        }

        $cassa->contoCorrente()->create([
            'iban'         => $data['iban'] ?? null,
            'istituto'     => $data['istituto'] ?? null,
            'swift'        => $data['bic'] ?? null,
            'intestatario' => $data['intestatario'] ?? $cassa->condominio->nome,
            'tipo'         => $data['tipo_conto'] ?? 'ordinario',
            'indirizzo'    => $data['indirizzo'] ?? null,
            'comune'       => $data['comune'] ?? null,
            'cap'          => $data['cap'] ?? null,
            'provincia'    => $data['provincia'] ?? null,
            'nazione'      => 'Italia',
            'predefinito'  => $isPredefinito, 
        ]);
    }
}