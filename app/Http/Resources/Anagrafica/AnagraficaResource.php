<?php

namespace App\Http\Resources\Anagrafica;

use App\Http\Resources\Condominio\CondominioResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnagraficaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'nome'      => $this->nome,
            'indirizzo' => $this->indirizzo,
            'condomini' => CondominioResource::collection($this->whenLoaded('condomini'))
        ];
    }
}
