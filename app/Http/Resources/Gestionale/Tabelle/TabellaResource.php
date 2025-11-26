<?php

namespace App\Http\Resources\Gestionale\Tabelle;

use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class TabellaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'id'                 => $this->id,
            'nome'               => Str::ucfirst($this->nome),
            'descrizione'        => $this->descrizione,
            'tipo'               => $this->tipo,
            'note'               => $this->note,
            'quota'              => $this->quota,
            'numero_decimali'    => $this->numero_decimali,
            'palazzina'          => new PalazzinaResource($this->whenLoaded('palazzina')),
            'scala'              => new ScalaResource($this->whenLoaded('scala')),
        ];
    }
}
