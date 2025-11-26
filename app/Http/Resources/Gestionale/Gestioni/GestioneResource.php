<?php

namespace App\Http\Resources\Gestionale\Gestioni;

use App\Http\Resources\Gestionale\Esercizi\EsercizioResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class GestioneResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         
         return [
            'id'                => $this->id,
            'nome'              => Str::ucfirst($this->nome),
            'descrizione'       => $this->descrizione,
            'note'              => $this->note,
            'tipo'              => $this->tipo,
            'data_inizio'       => $this->data_inizio,
            'data_fine'         => $this->data_fine,
            'esercizio'         => EsercizioResource::collection($this->whenLoaded('esercizi')),
        ];
    }
}
