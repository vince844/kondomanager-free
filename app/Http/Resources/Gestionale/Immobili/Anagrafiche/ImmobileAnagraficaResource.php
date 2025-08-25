<?php

namespace App\Http\Resources\Gestionale\Immobili\Anagrafiche;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImmobileAnagraficaResource extends JsonResource
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
            'pivot' => [
                'tipologia'       => $this->pivot->tipologia,
                'quota'           => $this->pivot->quota,
                'tipologie_spese' => $this->pivot->tipologie_spese,
                'data_inizio'     => $this->pivot->data_inizio,
                'data_fine'       => $this->pivot->data_fine,
                'attivo'          => $this->pivot->attivo,
                'note'            => $this->pivot->note,
            ],

        ];
    }
}
