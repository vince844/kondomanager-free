<?php

namespace App\Http\Resources\Commenti;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'corpo'        => $this->corpo,
            'stato'        => $this->stato,
            'user_id'      => $this->user_id,
            'created_at'   => $this->created_at?->toISOString(),
            'modificato_at' => $this->modificato_at?->toISOString(),
            'moderato_at'  => $this->moderato_at?->toISOString(),
            'deleted_at'   => $this->deleted_at?->toISOString(),
            'autore'       => $this->whenLoaded('autore', function () {
                return [
                    'id'         => $this->autore->id,
                    'name'       => $this->autore->name,
                    'anagrafica' => $this->autore->relationLoaded('anagrafica')
                        ? ['nome' => $this->autore->anagrafica?->nome]
                        : null,
                ];
            }),
        ];
    }
}
