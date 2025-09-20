<?php

namespace App\Http\Resources\Gestionale\Immobili;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TipologiaImmobileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
          return [
            'id'          => $this->id,
            'nome'        => $this->nome,
            'categoria'   => $this->categoria,
        ];
    }
}
