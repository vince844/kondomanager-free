<?php

namespace App\Http\Resources\Gestionale\Movimenti;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PagamentoFornitoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'data_pagamento'         => $this->data_pagamento?->format('Y-m-d'),
            'data_pagamento_fmt'     => $this->data_pagamento?->format('d/m/Y'),
            'metodo_pagamento'       => $this->metodo_pagamento?->value ?? $this->metodo_pagamento,
            'stato'                  => $this->stato?->value ?? $this->stato,
            'importo_lordo'          => $this->importo_lordo,
            'importo_netto'          => $this->importo_netto,
            'importo_ritenuta'       => $this->importo_ritenuta,
            'importo_commissione'    => $this->importo_commissione,
            'bonifico_parlante'      => $this->bonifico_parlante,
            'tipo_detrazione'        => $this->tipo_detrazione?->value ?? $this->tipo_detrazione,
            'storno_cross_esercizio' => $this->storno_cross_esercizio,
            'fornitore'              => $this->whenLoaded('fornitore', function () {
                return [
                    'id'              => $this->fornitore->id,
                    'ragione_sociale' => $this->fornitore->ragione_sociale,
                ];
            }),
            // Fallback allo snapshot se il fornitore reale è stato eliminato
            'fornitore_snapshot'     => $this->fornitore_snapshot,
            'conto_corrente'         => $this->whenLoaded('contoCorrente', function () {
                return [
                    'id'   => $this->contoCorrente->id,
                    'nome' => $this->contoCorrente->nome,
                    'iban' => $this->contoCorrente->iban,
                ];
            }),
            'scrittura'              => $this->whenLoaded('scrittura', function () {
                return [
                    'id'          => $this->scrittura->id,
                    'descrizione' => $this->scrittura->descrizione,
                ];
            }),
        ];
    }
}

