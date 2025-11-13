<?php

namespace App\Http\Resources\Gestionale\PianiRate;

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
            'id'                => $this->id,
            'nome'              => $this->nome,
            'descrizione'       => $this->descrizione
        ];
    }
}
