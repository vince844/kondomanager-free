<?php

namespace App\Http\Resources\Gestionale\PianiRate;

use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PianoRateResource extends JsonResource
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
            'descrizione' => $this->descrizione,
            'numero_rate' => $this->numero_rate,
            'data_inizio' => $this->data_inizio?->format('Y-m-d'),
            'gestione'    => new GestioneResource($this->whenLoaded('gestione')),
        ];
    }
}
