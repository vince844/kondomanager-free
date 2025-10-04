<?php

namespace App\Http\Resources\Gestionale\Esercizi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EsercizioResource extends JsonResource
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
            'data_inizio'       => $this->data_inizio,
            'data_fine'         => $this->data_fine,
            'stato'             => $this->stato,
        ];
    
    }
}
